<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanPaymentSchedule;
use App\Models\Repayment;
use App\Models\RepaymentDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RepaymentController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        $payload = $request->validate([
            'loanId' => ['required', 'integer', 'exists:loans,id'],
            'installmentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentMethod' => ['nullable', Rule::in(['Cash', 'Bank Transfer', 'Mobile Wallet'])],
            'paymentDate' => ['required', 'date'],
            'paymentProof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $loan = Loan::findOrFail($payload['loanId']);
        if ($user && $user->role === 'customer') {
            if (!$user->customer || $loan->customer_id !== $user->customer->id) {
                return response()->json(['message' => 'Loan account not found.'], 404);
            }
        } elseif ($user && $user->role !== 'admin') {
            $branchId = $this->branchIdForUser($user);
            if ($branchId && (int) optional($loan->customer)->branch_id !== $branchId) {
                return response()->json(['message' => 'Loan account is outside your branch access.'], 403);
            }
        }

        $repayment = DB::transaction(function () use ($payload, $loan, $request, $user) {
            $repayment = Repayment::create([
                'loan_id' => $payload['loanId'],
                'installment_amount' => $payload['installmentAmount'],
                'payment_method' => $payload['paymentMethod'] ?? 'Cash',
                'payment_date' => $payload['paymentDate'],
            ]);

            $this->applyPaymentToSchedule($loan, (float) $payload['installmentAmount']);

            if ($request->hasFile('paymentProof')) {
                $file = $request->file('paymentProof');
                $filename = sprintf('%s.%s', uniqid('repay_', true), $file->getClientOriginalExtension());
                $path = $file->storeAs('repayment-proofs', $filename, 'public');

                RepaymentDocument::create([
                    'repayment_id' => $repayment->id,
                    'uploaded_by' => $user ? $user->id : null,
                    'original_name' => $file->getClientOriginalName(),
                    'stored_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize(),
                ]);
            }

            return $repayment;
        });

        return response()->json([
            'id' => $repayment->id,
            'message' => 'Repayment posted successfully.',
        ], 201);
    }

    private function applyPaymentToSchedule(Loan $loan, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $schedules = LoanPaymentSchedule::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', ['Pending', 'Partial'])
            ->orderBy('due_date')
            ->get();

        $remaining = $amount;
        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $dueRemaining = (float) $schedule->amount_due - (float) $schedule->amount_paid;
            if ($dueRemaining <= 0) {
                $schedule->status = 'Paid';
                $schedule->save();
                continue;
            }

            $applied = min($remaining, $dueRemaining);
            $schedule->amount_paid = (float) $schedule->amount_paid + $applied;
            $remaining -= $applied;

            if ($schedule->amount_paid >= $schedule->amount_due) {
                $schedule->status = 'Paid';
            } else {
                $schedule->status = 'Partial';
            }
            $schedule->save();
        }

        $nextDue = LoanPaymentSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['Pending', 'Partial'])
            ->min('due_date');

        $loan->next_due_date = $nextDue ?: null;
        if (!$nextDue) {
            $loan->status = 'Closed';
        }
        $loan->save();

        $this->logAudit('loan.repayment_applied', $loan, [
            'amount' => $amount,
            'next_due_date' => $loan->next_due_date,
        ]);
    }
}
