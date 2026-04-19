<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanPaymentSchedule;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;

class LoanApprovalController extends Controller
{
    private $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    public function index()
    {
        $user = Auth::user();
        $role = $user->role;
        $branchId = $this->branchIdForUser($user);
        $loans = Loan::with('customer:id,full_name')
            ->where('requires_manager_approval', true)
            ->where('status', 'Pending')
            ->where('approval_role', $role)
            ->when($role !== 'admin' && $branchId, function ($query) use ($branchId) {
                $query->whereHas('customer', function ($customerQuery) use ($branchId) {
                    $customerQuery->where('branch_id', $branchId);
                });
            })
            ->orderByDesc('id')
            ->get();

        return view('manager.loan-approvals', [
            'loans' => $loans,
        ]);
    }

    public function approve(Request $request, Loan $loan)
    {
        $payload = $request->validate([
            'approval_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureReviewAccess($loan);

        DB::transaction(function () use ($loan, $payload) {
            $loan->status = 'Approved';
            $loan->approved_by = Auth::id();
            $loan->approved_at = now();
            $loan->rejected_at = null;
            $loan->approval_note = $payload['approval_note'] ?? null;
            $loan->rejection_reason = null;
            $loan->rejected_by = null;
            $loan->save();

            $this->generateSchedule($loan);
        });

        $this->logAudit('loan.approved', $loan, [
            'approval_role' => $loan->approval_role,
        ]);

        // Send notification to customer
        try {
            $this->notificationService->sendLoanApprovalNotification($loan);
        } catch (\Exception $e) {
            \Log::error('Failed to send loan approval notification: ' . $e->getMessage());
        }

        return redirect()->back()->with('status', 'Loan approved.');
    }

    public function decline(Request $request, Loan $loan)
    {
        $payload = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->ensureReviewAccess($loan);

        $loan->status = 'Rejected';
        $loan->approved_by = Auth::id();
        $loan->rejected_at = now();
        $loan->approved_at = null;
        $loan->rejection_reason = $payload['rejection_reason'];
        $loan->approval_note = null;
        $loan->rejected_by = Auth::id();
        $loan->save();

        $this->logAudit('loan.declined', $loan, [
            'approval_role' => $loan->approval_role,
        ]);

        // Send notification to customer
        try {
            $this->notificationService->sendLoanRejectionNotification($loan);
        } catch (\Exception $e) {
            \Log::error('Failed to send loan rejection notification: ' . $e->getMessage());
        }

        return redirect()->back()->with('status', 'Loan declined.');
    }

    /**
     * Close a loan account
     */
    public function closeLoan(Request $request, Loan $loan)
    {
        $payload = $request->validate([
            'closure_reason' => ['required', 'string', 'max:2000'],
            'confirm_closure' => ['accepted'],
        ]);

        $this->ensureReviewAccess($loan);

        // Ensure loan is approved and can be closed
        if ($loan->status !== 'Approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved loans can be closed'
            ]);
        }

        // Calculate closing balance (remaining principal)
        $totalPaid = $loan->repayments()->sum('installment_amount');
        $closingBalance = $loan->requested_amount - $totalPaid;

        try {
            $loan->update([
                'status' => 'Closed',
                'closed_by' => Auth::id(),
                'closed_at' => now(),
                'closure_reason' => $payload['closure_reason'],
                'closing_balance' => $closingBalance,
            ]);

            // Archive loan data (already preserved in database)
            
            // Notify customer
            try {
                $this->notificationService->sendCustomNotification(
                    $loan->customer,
                    sprintf(
                        'Your loan account (ID: %d) has been closed. Remaining balance: ETB %s. %s',
                        $loan->id,
                        number_format($closingBalance, 2),
                        $payload['closure_reason']
                    ),
                    'Loan Account Closed'
                );
            } catch (\Exception $e) {
                \Log::error('Failed to send loan closure notification: ' . $e->getMessage());
            }

            $this->logAudit('loan.closed', $loan, [
                'customer_id' => $loan->customer_id,
                'closing_balance' => $closingBalance,
                'closure_reason' => $payload['closure_reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Loan account closed successfully',
                'closing_balance' => $closingBalance
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to close loan: ' . $e->getMessage()
            ]);
        }
    }

    private function generateSchedule(Loan $loan): void
    {
        if ($loan->schedules()->exists()) {
            return;
        }

        $periodDays = match ($loan->repayment_frequency) {
            'Weekly' => 7,
            'Bi-Weekly' => 14,
            default => 30,
        };

        $totalPeriods = (int) max(1, ceil(($loan->term_months * 30) / $periodDays));
        $principal = (float) $loan->requested_amount;
        $interestRate = (float) $loan->interest_rate;
        $totalRepayable = $principal * (1 + ($interestRate / 100) * ($loan->term_months / 12));

        $baseAmount = round($totalRepayable / $totalPeriods, 2);
        $totalAssigned = 0.0;
        $startDate = Date::parse($loan->application_date);

        for ($i = 1; $i <= $totalPeriods; $i++) {
            $amountDue = $i === $totalPeriods
                ? round($totalRepayable - $totalAssigned, 2)
                : $baseAmount;
            $totalAssigned += $amountDue;

            LoanPaymentSchedule::create([
                'loan_id' => $loan->id,
                'installment_number' => $i,
                'due_date' => $startDate->copy()->addDays($periodDays * $i),
                'amount_due' => $amountDue,
                'amount_paid' => 0,
                'status' => 'Pending',
            ]);
        }

        $loan->next_due_date = $loan->schedules()->min('due_date');
        $loan->save();
    }

    private function ensureReviewAccess(Loan $loan): void
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);

        if ($loan->approval_role !== $user->role) {
            abort(403);
        }

        if ($user->role !== 'admin' && $branchId && (int) optional($loan->customer)->branch_id !== $branchId) {
            abort(403);
        }
    }
}
