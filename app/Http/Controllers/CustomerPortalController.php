<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\CustomerUpdateRequest;
use Illuminate\Support\Facades\Auth;

class CustomerPortalController extends Controller
{
    public function dashboard()
    {
        $user = Auth::user();
        $customer = $user->customer;
        $upcomingPayments = [];
        $loanSummaries = collect();
        $savingsAccounts = collect();
        $loanBalance = 0.0;
        $savingsBalance = 0.0;
        $updateRequests = collect();

        if ($customer) {
            $upcomingPayments = Loan::where('customer_id', $customer->id)
                ->whereNotNull('next_due_date')
                ->whereBetween('next_due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->orderBy('next_due_date')
                ->get();

            $loanSummaries = Loan::query()
                ->where('customer_id', $customer->id)
                ->withSum('schedules', 'amount_due')
                ->withSum('repayments', 'installment_amount')
                ->select('id', 'loan_product', 'status', 'interest_rate', 'next_due_date')
                ->orderByDesc('id')
                ->get()
                ->map(function (Loan $loan) {
                    $totalDue = (float) ($loan->schedules_sum_amount_due ?? 0);
                    $totalPaid = (float) ($loan->repayments_sum_installment_amount ?? 0);
                    $balance = max(0, $totalDue - $totalPaid);

                    return [
                        'id' => $loan->id,
                        'product' => $loan->loan_product,
                        'status' => $loan->status,
                        'interest_rate' => $loan->interest_rate,
                        'nextDue' => optional($loan->next_due_date)->toDateString(),
                        'totalDue' => $totalDue,
                        'totalPaid' => $totalPaid,
                        'balance' => $balance,
                    ];
                });

            $loanBalance = (float) $loanSummaries->sum('balance');

            $savingsAccounts = SavingsAccount::query()
                ->where('customer_id', $customer->id)
                ->withSum('transactions', 'amount')
                ->select('id', 'account_number', 'savings_type', 'status', 'interest_rate', 'maturity_date')
                ->orderByDesc('id')
                ->get();

            $savingsBalance = (float) $savingsAccounts->sum('transactions_sum_amount');

            $updateRequests = CustomerUpdateRequest::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->limit(5)
                ->get();
        }

        return view('customer.dashboard', [
            'upcomingPayments' => $upcomingPayments,
            'loanSummaries' => $loanSummaries,
            'loanBalance' => $loanBalance,
            'savingsAccounts' => $savingsAccounts,
            'savingsBalance' => $savingsBalance,
            'updateRequests' => $updateRequests,
        ]);
    }

    public function loanForm()
    {
        return view('customer.loan-apply');
    }

    public function repaymentForm()
    {
        return view('customer.repayment');
    }

    public function loanCalculator()
    {
        return view('customer.loan-calculator');
    }

    public function updateRequest()
    {
        $allowedFields = [
            'full_name',
            'phone_number',
            'email_address',
            'address',
            'occupation',
            'education_level',
            'marital_status',
            'dependents_count',
            'employment_status',
            'monthly_income'
        ];

        $requests = CustomerUpdateRequest::query()
            ->where('customer_id', Auth::user()->customer->id)
            ->latest('id')
            ->limit(10)
            ->get();

        return view('customer.update-request', [
            'allowedFields' => $allowedFields,
            'requests' => $requests
        ]);
    }
}
