<?php

namespace App\Services;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Carbon\Carbon;

class InterestCalculationService
{
    /**
     * Calculate interest for a savings account
     */
    public function calculateInterest($accountId, $calculationDate = null)
    {
        $account = SavingsAccount::with('transactions')->findOrFail($accountId);
        
        if (!$calculationDate) {
            $calculationDate = now();
        } else {
            $calculationDate = Carbon::parse($calculationDate);
        }

        $interestRate = $account->interest_rate / 100; // Convert percentage to decimal
        $currentBalance = $account->balance;
        
        // Calculate daily interest
        $dailyInterestRate = $interestRate / 365;
        $dailyInterest = $currentBalance * $dailyInterestRate;
        
        // Calculate monthly interest (assuming 30 days)
        $monthlyInterest = $dailyInterest * 30;
        
        // Calculate annual interest
        $annualInterest = $currentBalance * $interestRate;
        
        // Calculate interest for specific period
        $lastInterestDate = $account->last_interest_applied_at ?? $account->opened_at;
        $daysSinceLastInterest = $lastInterestDate->diffInDays($calculationDate);
        $periodInterest = $currentBalance * ($interestRate / 365) * $daysSinceLastInterest;

        return [
            'account_id' => $account->id,
            'account_number' => $account->account_number,
            'current_balance' => $currentBalance,
            'interest_rate' => $account->interest_rate,
            'calculation_date' => $calculationDate->toIso8601String(),
            'last_interest_applied' => $account->last_interest_applied_at ? $account->last_interest_applied_at->toIso8601String() : null,
            'days_since_last_interest' => $daysSinceLastInterest,
            'daily_interest' => round($dailyInterest, 2),
            'monthly_interest' => round($monthlyInterest, 2),
            'annual_interest' => round($annualInterest, 2),
            'period_interest' => round($periodInterest, 2),
            'calculation_method' => 'simple_interest',
        ];
    }

    /**
     * Calculate compound interest
     */
    public function calculateCompoundInterest($principal, $rate, $time, $frequency = 1)
    {
        // A = P(1 + r/n)^(nt)
        // Where:
        // A = final amount
        // P = principal
        // r = annual interest rate (decimal)
        // n = times interest applied per time period
        // t = number of time periods
        
        $decimalRate = $rate / 100;
        $amount = $principal * pow(1 + ($decimalRate / $frequency), $frequency * $time);
        $interest = $amount - $principal;
        
        return [
            'principal' => $principal,
            'rate' => $rate,
            'time' => $time,
            'frequency' => $frequency,
            'final_amount' => round($amount, 2),
            'interest_earned' => round($interest, 2),
            'calculation_method' => 'compound_interest',
        ];
    }

    /**
     * Calculate interest for multiple accounts
     */
    public function calculateBulkInterest($accountIds, $calculationDate = null)
    {
        $results = [];
        
        foreach ($accountIds as $accountId) {
            try {
                $results[$accountId] = $this->calculateInterest($accountId, $calculationDate);
            } catch (\Exception $e) {
                $results[$accountId] = [
                    'error' => $e->getMessage(),
                    'account_id' => $accountId,
                ];
            }
        }
        
        return $results;
    }

    /**
     * Apply calculated interest to account
     */
    public function applyInterestToAccount($accountId, $interestAmount, $appliedBy = null, $notes = null)
    {
        $account = SavingsAccount::findOrFail($accountId);
        
        // Create interest transaction
        $transaction = SavingsTransaction::create([
            'savings_account_id' => $accountId,
            'transaction_type' => 'interest',
            'amount' => $interestAmount,
            'transaction_date' => now(),
            'balance_after' => $account->balance + $interestAmount,
            'notes' => $notes ?? 'Interest applied',
            'created_by' => $appliedBy,
        ]);
        
        // Update account balance
        $account->update([
            'balance' => $account->balance + $interestAmount,
            'last_interest_applied_at' => now(),
        ]);
        
        return [
            'success' => true,
            'transaction_id' => $transaction->id,
            'new_balance' => $account->balance,
            'interest_applied' => $interestAmount,
        ];
    }

    /**
     * Calculate interest projection
     */
    public function calculateInterestProjection($accountId, $months = 12)
    {
        $account = SavingsAccount::findOrFail($accountId);
        $currentBalance = $account->balance;
        $interestRate = $account->interest_rate / 100;
        
        $projection = [];
        $projectedBalance = $currentBalance;
        
        for ($i = 1; $i <= $months; $i++) {
            $monthlyInterest = $projectedBalance * ($interestRate / 12);
            $projectedBalance += $monthlyInterest;
            
            $projection[] = [
                'month' => $i,
                'interest_earned' => round($monthlyInterest, 2),
                'projected_balance' => round($projectedBalance, 2),
                'date' => now()->addMonths($i)->format('Y-m-d'),
            ];
        }
        
        return [
            'account_id' => $account->id,
            'account_number' => $account->account_number,
            'current_balance' => $currentBalance,
            'interest_rate' => $account->interest_rate,
            'projection_months' => $months,
            'projections' => $projection,
            'total_interest_earned' => round(array_sum(array_column($projection, 'interest_earned')), 2),
            'final_projected_balance' => end($projection)['projected_balance'],
        ];
    }

    /**
     * Compare interest calculation methods
     */
    public function compareInterestMethods($principal, $rate, $time)
    {
        $simpleInterest = $principal * ($rate / 100) * $time;
        
        // Monthly compounding
        $compoundMonthly = $principal * pow(1 + ($rate / 100 / 12), 12 * $time) - $principal;
        
        // Daily compounding
        $compoundDaily = $principal * pow(1 + ($rate / 100 / 365), 365 * $time) - $principal;
        
        // Continuous compounding
        $compoundContinuous = $principal * (exp($rate / 100 * $time) - 1);
        
        return [
            'principal' => $principal,
            'rate' => $rate,
            'time' => $time,
            'simple_interest' => round($simpleInterest, 2),
            'compound_monthly' => round($compoundMonthly, 2),
            'compound_daily' => round($compoundDaily, 2),
            'compound_continuous' => round($compoundContinuous, 2),
            'best_method' => 'compound_continuous',
            'difference_best_to_simple' => round($compoundContinuous - $simpleInterest, 2),
        ];
    }

    /**
     * Calculate interest for loan
     */
    public function calculateLoanInterest($principal, $rate, $termMonths, $repaymentFrequency = 'monthly')
    {
        $monthlyRate = $rate / 100 / 12;
        
        // Calculate monthly payment using amortization formula
        if ($monthlyRate == 0) {
            $monthlyPayment = $principal / $termMonths;
        } else {
            $monthlyPayment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $termMonths)) / (pow(1 + $monthlyRate, $termMonths) - 1);
        }
        
        $totalPayment = $monthlyPayment * $termMonths;
        $totalInterest = $totalPayment - $principal;
        
        return [
            'principal' => $principal,
            'rate' => $rate,
            'term_months' => $termMonths,
            'monthly_payment' => round($monthlyPayment, 2),
            'total_payment' => round($totalPayment, 2),
            'total_interest' => round($totalInterest, 2),
            'effective_rate' => round(($totalInterest / $principal) * 100, 2),
        ];
    }

    /**
     * Get interest calculation summary for branch
     */
    public function getBranchInterestSummary($branchId, $startDate = null, $endDate = null)
    {
        if (!$startDate) {
            $startDate = now()->startOfMonth();
        }
        if (!$endDate) {
            $endDate = now()->endOfMonth();
        }

        $accounts = SavingsAccount::where('branch_id', $branchId)
            ->where('status', 'active')
            ->get();

        $totalInterestApplied = 0;
        $accountSummaries = [];

        foreach ($accounts as $account) {
            $interestTransactions = SavingsTransaction::where('savings_account_id', $account->id)
                ->where('transaction_type', 'interest')
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount');

            $totalInterestApplied += $interestTransactions;

            $accountSummaries[] = [
                'account_number' => $account->account_number,
                'balance' => $account->balance,
                'interest_rate' => $account->interest_rate,
                'interest_applied_period' => $interestTransactions,
            ];
        }

        return [
            'branch_id' => $branchId,
            'period_start' => $startDate->format('Y-m-d'),
            'period_end' => $endDate->format('Y-m-d'),
            'total_accounts' => $accounts->count(),
            'total_interest_applied' => round($totalInterestApplied, 2),
            'account_summaries' => $accountSummaries,
        ];
    }
}
