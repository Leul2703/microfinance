<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanEscalation;
use App\Models\User;
use App\Models\Customer;
use App\Models\Branch;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HeadCEOController extends Controller
{
    public function dashboard()
    {
        $pendingEscalations = LoanEscalation::with(['loan.customer', 'requester'])
            ->pendingReview()
            ->latest('escalated_at')
            ->get();

        $recentDecisions = LoanEscalation::with(['loan.customer', 'requester', 'reviewer'])
            ->whereIn('status', ['ceo_approved', 'ceo_rejected'])
            ->latest('reviewed_at')
            ->limit(10)
            ->get();

        $stats = [
            'pending_count' => LoanEscalation::pendingReview()->count(),
            'approved_count' => LoanEscalation::approved()->count(),
            'rejected_count' => LoanEscalation::rejected()->count(),
            'total_escalated' => LoanEscalation::count(),
        ];

        return view('ceo.dashboard', compact('pendingEscalations', 'recentDecisions', 'stats'));
    }

    public function accountManagement()
    {
        $systemStats = $this->getSystemStatistics();
        $recentUsers = User::with('customer')->latest()->limit(10)->get();
        $branches = Branch::withCount('customers')->get();

        return view('ceo.account-management', compact('systemStats', 'recentUsers', 'branches'));
    }

    public function viewUsers()
    {
        $users = User::with(['customer', 'branch'])
            ->when(request('role'), function ($query, $role) {
                return $query->where('role', $role);
            })
            ->when(request('search'), function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(20);

        return view('ceo.users', compact('users'));
    }

    public function viewUser($userId)
    {
        $user = User::with(['customer', 'branch', 'employees'])
            ->findOrFail($userId);

        $userActivity = [];
        if (class_exists('\App\Models\AuditLog')) {
            $userActivity = \App\Models\AuditLog::where('user_id', $userId)
                ->latest()
                ->limit(20)
                ->get();
        }

        return view('ceo.user-details', compact('user', 'userActivity'));
    }

    public function manageBranches()
    {
        $branches = Branch::withCount('customers')
            ->withCount(['loans' => function ($query) {
                $query->where('status', 'Approved');
            }])
            ->latest()
            ->get();

        return view('ceo.branches', compact('branches'));
    }

    public function createBranch(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:branches,name',
            'location' => 'nullable|string|max:255',
        ]);

        $branch = Branch::create([
            'name' => $request->name,
            'location' => $request->location,
        ]);

        $this->logAudit('branch.created', $branch, [
            'name' => $branch->name,
            'location' => $branch->location,
        ]);

        return redirect()->back()->with('status', 'Branch created successfully.');
    }

    public function updateBranch(Request $request, $branchId)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:branches,name,' . $branchId,
            'location' => 'nullable|string|max:255',
        ]);

        $branch = Branch::findOrFail($branchId);
        $branch->update([
            'name' => $request->name,
            'location' => $request->location,
        ]);

        $this->logAudit('branch.updated', $branch, [
            'name' => $branch->name,
            'location' => $branch->location,
        ]);

        return redirect()->back()->with('status', 'Branch updated successfully.');
    }

    public function systemOverview()
    {
        $overview = $this->getSystemStatistics();
        $recentActivity = [];

        if (class_exists('\App\Models\AuditLog')) {
            $recentActivity = \App\Models\AuditLog::with('user')
                ->latest()
                ->limit(50)
                ->get();
        }

        return view('ceo.system-overview', compact('overview', 'recentActivity'));
    }

    private function getSystemStatistics()
    {
        return [
            'total_users' => User::count(),
            'total_customers' => Customer::count(),
            'total_branches' => Branch::count(),
            'active_loans' => Loan::where('status', 'Approved')->count(),
            'pending_loans' => Loan::where('status', 'Pending')->count(),
            'active_savings' => SavingsAccount::where('status', 'active')->count(),
            'pending_escalations' => LoanEscalation::pendingReview()->count(),
            'users_by_role' => User::selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->get()
                ->pluck('count', 'role'),
            'customers_by_branch' => Branch::withCount('customers')
                ->get()
                ->pluck('customers_count', 'name'),
        ];
    }

    public function reviewLoan($loanId)
    {
        $loan = Loan::with(['customer', 'creator', 'escalations.requester'])
            ->findOrFail($loanId);

        $escalation = $loan->latestEscalation();

        if (!$escalation || $escalation->status !== 'pending_ceo_review') {
            return redirect()->route('ceo.dashboard')
                ->with('error', 'This loan is not pending CEO review.');
        }

        return view('ceo.review-loan', compact('loan', 'escalation'));
    }

    public function approveLoan(Request $request, $loanId)
    {
        $request->validate([
            'review_note' => 'required|string|max:1000',
        ]);

        $loan = Loan::findOrFail($loanId);
        $escalation = $loan->latestEscalation();

        if (!$escalation || $escalation->status !== 'pending_ceo_review') {
            return response()->json(['message' => 'Invalid escalation status.'], 400);
        }

        DB::beginTransaction();
        try {
            $escalation->update([
                'status' => 'ceo_approved',
                'reviewed_by' => Auth::id(),
                'review_note' => $request->review_note,
                'reviewed_at' => now(),
            ]);

            $loan->update([
                'status' => 'Approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'approval_note' => $request->review_note,
            ]);

            $this->generatePaymentSchedule($loan);

            $this->logAudit('loan.ceo_approved', $loan, [
                'escalation_id' => $escalation->id,
                'review_note' => $request->review_note,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Loan approved successfully.',
                'redirect' => route('ceo.dashboard')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error approving loan: ' . $e->getMessage()], 500);
        }
    }

    public function rejectLoan(Request $request, $loanId)
    {
        $request->validate([
            'review_note' => 'required|string|max:1000',
        ]);

        $loan = Loan::findOrFail($loanId);
        $escalation = $loan->latestEscalation();

        if (!$escalation || $escalation->status !== 'pending_ceo_review') {
            return response()->json(['message' => 'Invalid escalation status.'], 400);
        }

        DB::beginTransaction();
        try {
            $escalation->update([
                'status' => 'ceo_rejected',
                'reviewed_by' => Auth::id(),
                'review_note' => $request->review_note,
                'reviewed_at' => now(),
            ]);

            $loan->update([
                'status' => 'Rejected',
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
                'rejection_reason' => $request->review_note,
            ]);

            $this->logAudit('loan.ceo_rejected', $loan, [
                'escalation_id' => $escalation->id,
                'review_note' => $request->review_note,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Loan rejected successfully.',
                'redirect' => route('ceo.dashboard')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error rejecting loan: ' . $e->getMessage()], 500);
        }
    }

    private function generatePaymentSchedule(Loan $loan)
    {
        $startDate = $loan->application_date;
        $monthlyPayment = $this->calculateMonthlyPayment($loan);

        $schedules = [];
        for ($i = 1; $i <= $loan->term_months; $i++) {
            $dueDate = $startDate->copy()->addMonths($i);
            $schedules[] = [
                'loan_id' => $loan->id,
                'installment_number' => $i,
                'due_date' => $dueDate,
                'amount_due' => $monthlyPayment,
                'amount_paid' => 0,
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('loan_payment_schedules')->insert($schedules);
        $loan->update(['next_due_date' => $startDate->copy()->addMonth()]);
    }

    private function calculateMonthlyPayment(Loan $loan)
    {
        $principal = $loan->requested_amount;
        $monthlyRate = $loan->interest_rate / 100 / 12;
        $months = $loan->term_months;

        if ($monthlyRate == 0) {
            return $principal / $months;
        }

        return $principal * ($monthlyRate * pow(1 + $monthlyRate, $months)) / (pow(1 + $monthlyRate, $months) - 1);
    }

    protected function logAudit(string $action, $auditable = null, array $metadata = []): void
    {
        if (class_exists('\App\Models\AuditLog')) {
            \App\Models\AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'model_type' => $auditable ? get_class($auditable) : null,
                'model_id' => $auditable ? $auditable->id : null,
                'data' => json_encode($metadata),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
    }
}

