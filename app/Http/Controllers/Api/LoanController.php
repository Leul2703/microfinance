<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LoanController extends Controller
{
    private const LOAN_PRODUCTS = [
        'Individual Loan',
        'Group Loan',
        'Business Loan',
        'Agriculture Loan',
        'Emergency Loan',
    ];

    public function index()
    {
        $query = Loan::with('customer:id,full_name');
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);
        if ($user && $user->role === 'customer' && $user->customer) {
            $query->where('customer_id', $user->customer->id);
        } elseif ($user && $user->role !== 'admin' && $branchId) {
            $query->whereHas('customer', function ($customerQuery) use ($branchId) {
                $customerQuery->where('branch_id', $branchId);
            });
        }

        return $query
            ->latest('id')
            ->get()
            ->map(function (Loan $loan) {
                return [
                    'id' => $loan->id,
                    'customerId' => $loan->customer_id,
                    'customerName' => optional($loan->customer)->full_name,
                    'loanProduct' => $loan->loan_product,
                    'requestedAmount' => (float) $loan->requested_amount,
                    'termMonths' => $loan->term_months,
                    'interestRate' => (float) $loan->interest_rate,
                    'status' => $loan->status,
                    'applicationDate' => optional($loan->application_date)->toDateString(),
                    'requiresApproval' => $loan->requires_manager_approval,
                    'nextDueDate' => optional($loan->next_due_date)->toDateString(),
                    'approvalRole' => $loan->approval_role,
                    'approvalNote' => $loan->approval_note,
                    'rejectionReason' => $loan->rejection_reason,
                ];
            });
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $rules = [
            'loanProduct' => ['required', Rule::in(self::LOAN_PRODUCTS)],
            'requestedAmount' => ['required', 'numeric', 'min:100'],
            'termMonths' => ['required', 'integer', 'min:1', 'max:60'],
            'interestRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'applicationDate' => ['required', 'date'],
            'repaymentFrequency' => ['nullable', Rule::in(['Monthly', 'Bi-Weekly', 'Weekly'])],
            'purpose' => ['required', 'string'],
        ];

        if ($user && $user->role === 'customer') {
            $rules['customerId'] = ['nullable'];
        } else {
            $rules['customerId'] = ['required', 'integer', 'exists:customers,id'];
        }

        $payload = $request->validate($rules);

        if ($user && $user->role === 'customer') {
            if (!$user->customer) {
                return response()->json(['message' => 'Customer profile not found.'], 404);
            }
            $payload['customerId'] = $user->customer->id;
        } elseif ($user && $user->role !== 'admin') {
            $branchId = $this->branchIdForUser($user);
            $customerInBranch = \App\Models\Customer::where('id', $payload['customerId'])
                ->when($branchId, function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                })
                ->exists();

            if (!$customerInBranch) {
                return response()->json(['message' => 'Customer is outside your branch access.'], 403);
            }
        }

        $requiresApproval = true;
        $approvalRole = $payload['requestedAmount'] > 100000 ? 'head_ceo' : 'loan_manager';
        $status = 'Pending';
        $loan = Loan::create([
            'customer_id' => $payload['customerId'],
            'loan_product' => $payload['loanProduct'],
            'requested_amount' => $payload['requestedAmount'],
            'term_months' => $payload['termMonths'],
            'interest_rate' => $payload['interestRate'] ?? 8.5,
            'application_date' => $payload['applicationDate'],
            'repayment_frequency' => $payload['repaymentFrequency'] ?? 'Monthly',
            'purpose' => $payload['purpose'],
            'status' => $status,
            'requires_manager_approval' => $requiresApproval,
            'approval_role' => $approvalRole,
            'next_due_date' => null,
            'created_by' => $user ? $user->id : null,
        ]);

        $this->logAudit('loan.created', $loan, [
            'customer_id' => $loan->customer_id,
            'approval_role' => $approvalRole,
        ]);

        return response()->json([
            'id' => $loan->id,
            'message' => 'Loan application submitted successfully.',
        ], 201);
    }

}
