<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Carbon\Carbon;

class BranchFinancialService
{
    /**
     * Get comprehensive financial summary for a branch
     */
    public function getBranchFinancialSummary($branchId, $startDate = null, $endDate = null)
    {
        $branch = Branch::with('customers')->findOrFail($branchId);
        
        // Default to current month if no dates provided
        if (!$startDate) {
            $startDate = Carbon::now()->startOfMonth();
        }
        if (!$endDate) {
            $endDate = Carbon::now()->endOfMonth();
        }

        // Get branch customers
        $customerIds = $branch->customers->pluck('id');

        // Loan metrics
        $loanMetrics = $this->getLoanMetrics($customerIds, $startDate, $endDate);
        
        // Savings metrics
        $savingsMetrics = $this->getSavingsMetrics($customerIds, $startDate, $endDate);
        
        // Repayment metrics
        $repaymentMetrics = $this->getRepaymentMetrics($customerIds, $startDate, $endDate);
        
        // Customer metrics
        $customerMetrics = $this->getCustomerMetrics($customerIds, $startDate, $endDate);

        return [
            'branch' => $branch,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'loans' => $loanMetrics,
            'savings' => $savingsMetrics,
            'repayments' => $repaymentMetrics,
            'customers' => $customerMetrics,
            'summary' => $this->calculateOverallSummary($loanMetrics, $savingsMetrics, $repaymentMetrics),
        ];
    }

    /**
     * Get loan-related metrics for a branch
     */
    private function getLoanMetrics($customerIds, $startDate, $endDate)
    {
        $totalLoans = Loan::whereIn('customer_id', $customerIds)->count();
        $activeLoans = Loan::whereIn('customer_id', $customerIds)
            ->where('status', 'Approved')
            ->count();
        
        $totalLoanAmount = Loan::whereIn('customer_id', $customerIds)
            ->where('status', 'Approved')
            ->sum('requested_amount');
        
        $newLoansThisPeriod = Loan::whereIn('customer_id', $customerIds)
            ->whereBetween('application_date', [$startDate, $endDate])
            ->count();
        
        $newLoanAmountThisPeriod = Loan::whereIn('customer_id', $customerIds)
            ->whereBetween('application_date', [$startDate, $endDate])
            ->sum('requested_amount');
        
        $closedLoansThisPeriod = Loan::whereIn('customer_id', $customerIds)
            ->where('status', 'Closed')
            ->whereBetween('closed_at', [$startDate, $endDate])
            ->count();

        // Loan status breakdown
        $loanStatusBreakdown = Loan::whereIn('customer_id', $customerIds)
            ->selectRaw('status, COUNT(*) as count, SUM(requested_amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'total_loans' => $totalLoans,
            'active_loans' => $activeLoans,
            'total_loan_portfolio' => $totalLoanAmount,
            'new_loans_period' => $newLoansThisPeriod,
            'new_loan_amount_period' => $newLoanAmountThisPeriod,
            'closed_loans_period' => $closedLoansThisPeriod,
            'status_breakdown' => $loanStatusBreakdown,
            'average_loan_size' => $activeLoans > 0 ? $totalLoanAmount / $activeLoans : 0,
        ];
    }

    /**
     * Get savings-related metrics for a branch
     */
    private function getSavingsMetrics($customerIds, $startDate, $endDate)
    {
        $totalSavingsAccounts = SavingsAccount::whereIn('customer_id', $customerIds)->count();
        $activeSavingsAccounts = SavingsAccount::whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->count();
        
        $totalSavingsBalance = SavingsAccount::whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->sum('balance');
        
        $newSavingsAccountsThisPeriod = SavingsAccount::whereIn('customer_id', $customerIds)
            ->whereBetween('opened_at', [$startDate, $endDate])
            ->count();
        
        // Transaction metrics
        $depositsThisPeriod = SavingsTransaction::whereIn('savings_account_id', 
            SavingsAccount::whereIn('customer_id', $customerIds)->pluck('id'))
            ->where('transaction_type', 'deposit')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');
        
        $withdrawalsThisPeriod = SavingsTransaction::whereIn('savings_account_id',
            SavingsAccount::whereIn('customer_id', $customerIds)->pluck('id'))
            ->where('transaction_type', 'withdrawal')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        $interestAppliedThisPeriod = SavingsTransaction::whereIn('savings_account_id',
            SavingsAccount::whereIn('customer_id', $customerIds)->pluck('id'))
            ->where('transaction_type', 'interest')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        return [
            'total_accounts' => $totalSavingsAccounts,
            'active_accounts' => $activeSavingsAccounts,
            'total_balance' => $totalSavingsBalance,
            'new_accounts_period' => $newSavingsAccountsThisPeriod,
            'deposits_period' => $depositsThisPeriod,
            'withdrawals_period' => $withdrawalsThisPeriod,
            'interest_applied_period' => $interestAppliedThisPeriod,
            'net_flow_period' => $depositsThisPeriod - $withdrawalsThisPeriod,
            'average_balance_per_account' => $activeSavingsAccounts > 0 ? $totalSavingsBalance / $activeSavingsAccounts : 0,
        ];
    }

    /**
     * Get repayment-related metrics for a branch
     */
    private function getRepaymentMetrics($customerIds, $startDate, $endDate)
    {
        $totalRepayments = Repayment::whereIn('loan_id', 
            Loan::whereIn('customer_id', $customerIds)->pluck('id'))
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->sum('installment_amount');
        
        $totalRepaymentsCount = Repayment::whereIn('loan_id',
            Loan::whereIn('customer_id', $customerIds)->pluck('id'))
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->count();

        // Repayment breakdown by type
        $repaymentByType = Repayment::whereIn('loan_id',
            Loan::whereIn('customer_id', $customerIds)->pluck('id'))
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->selectRaw('payment_type, COUNT(*) as count, SUM(installment_amount) as total_amount')
            ->groupBy('payment_type')
            ->get()
            ->keyBy('payment_type');

        // Overdue payments
        $overdueAmount = Loan::whereIn('customer_id', $customerIds)
            ->where('status', 'Approved')
            ->where('next_due_date', '<', Carbon::now())
            ->sum('requested_amount');

        return [
            'total_collected_period' => $totalRepayments,
            'total_transactions_period' => $totalRepaymentsCount,
            'by_type' => $repaymentByType,
            'overdue_amount' => $overdueAmount,
            'collection_rate' => $this->calculateCollectionRate($customerIds, $startDate, $endDate),
        ];
    }

    /**
     * Get customer-related metrics for a branch
     */
    private function getCustomerMetrics($customerIds, $startDate, $endDate)
    {
        $totalCustomers = Customer::whereIn('id', $customerIds)->count();
        
        $newCustomersThisPeriod = Customer::whereIn('id', $customerIds)
            ->whereBetween('registration_date', [$startDate, $endDate])
            ->count();
        
        // Inclusion metrics
        $womenCustomers = Customer::whereIn('id', $customerIds)->where('is_woman', true)->count();
        $disabledCustomers = Customer::whereIn('id', $customerIds)->where('has_disability', true)->count();
        
        // Active customers (those with active loans or savings)
        $activeLoanCustomers = Loan::whereIn('customer_id', $customerIds)
            ->where('status', 'Approved')
            ->distinct('customer_id')
            ->count('customer_id');
        
        $activeSavingsCustomers = SavingsAccount::whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->distinct('customer_id')
            ->count('customer_id');

        return [
            'total_customers' => $totalCustomers,
            'new_customers_period' => $newCustomersThisPeriod,
            'women_percentage' => $totalCustomers > 0 ? ($womenCustomers / $totalCustomers) * 100 : 0,
            'disabled_percentage' => $totalCustomers > 0 ? ($disabledCustomers / $totalCustomers) * 100 : 0,
            'active_loan_customers' => $activeLoanCustomers,
            'active_savings_customers' => $activeSavingsCustomers,
            'total_active_customers' => $activeLoanCustomers + $activeSavingsCustomers,
        ];
    }

    /**
     * Calculate overall financial summary
     */
    private function calculateOverallSummary($loanMetrics, $savingsMetrics, $repaymentMetrics)
    {
        return [
            'total_assets' => $loanMetrics['total_loan_portfolio'] + $savingsMetrics['total_balance'],
            'total_liabilities' => $savingsMetrics['total_balance'], // Savings are liabilities for the institution
            'net_period_income' => $repaymentMetrics['total_collected_period'] + $savingsMetrics['interest_applied_period'],
            'period_growth' => $savingsMetrics['net_flow_period'] + $loanMetrics['new_loan_amount_period'],
        ];
    }

    /**
     * Calculate collection rate
     */
    private function calculateCollectionRate($customerIds, $startDate, $endDate)
    {
        $totalDue = Loan::whereIn('customer_id', $customerIds)
            ->where('status', 'Approved')
            ->whereBetween('next_due_date', [$startDate, $endDate])
            ->sum('requested_amount');
        
        $totalCollected = Repayment::whereIn('loan_id',
            Loan::whereIn('customer_id', $customerIds)->pluck('id'))
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->sum('installment_amount');

        return $totalDue > 0 ? ($totalCollected / $totalDue) * 100 : 100;
    }

    /**
     * Get comparison data for multiple branches
     */
    public function getBranchComparison($branchIds = null, $startDate = null, $endDate = null)
    {
        if (!$branchIds) {
            $branchIds = Branch::pluck('id');
        }

        $comparison = [];
        foreach ($branchIds as $branchId) {
            $comparison[] = $this->getBranchFinancialSummary($branchId, $startDate, $endDate);
        }

        // Sort by total assets descending
        usort($comparison, function ($a, $b) {
            return $b['summary']['total_assets'] <=> $a['summary']['total_assets'];
        });

        return $comparison;
    }

    /**
     * Get trend data for a branch over time
     */
    public function getBranchTrends($branchId, $months = 12)
    {
        $trends = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $startDate = Carbon::now()->subMonths($i)->startOfMonth();
            $endDate = Carbon::now()->subMonths($i)->endOfMonth();
            
            $monthlyData = $this->getBranchFinancialSummary($branchId, $startDate, $endDate);
            $trends[] = [
                'month' => $startDate->format('M Y'),
                'data' => $monthlyData,
            ];
        }

        return $trends;
    }
}
