<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InclusionReportController extends Controller
{
    public function index()
    {
        return view('admin.inclusion-reports');
    }

    public function generateReport(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:customers,loans,savings,comprehensive',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $branchId = $request->branch_id;

        switch ($request->report_type) {
            case 'customers':
                return $this->generateCustomerReport($startDate, $endDate, $branchId);
            case 'loans':
                return $this->generateLoanReport($startDate, $endDate, $branchId);
            case 'savings':
                return $this->generateSavingsReport($startDate, $endDate, $branchId);
            case 'comprehensive':
                return $this->generateComprehensiveReport($startDate, $endDate, $branchId);
        }
    }

    private function generateCustomerReport($startDate, $endDate, $branchId)
    {
        $query = Customer::query()
            ->whereBetween('registration_date', [$startDate, $endDate]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $totalCustomers = $query->count();
        $womenCount = $query->women()->count();
        $disabilityCount = $query->personsDisability()->count();
        $womenDisabilityCount = $query->women()->personsDisability()->count();

        // Gender breakdown
        $genderBreakdown = Customer::select('gender', DB::raw('count(*) as count'))
            ->whereBetween('registration_date', [$startDate, $endDate])
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->groupBy('gender')
            ->get();

        // Education level breakdown
        $educationBreakdown = Customer::select('education_level', DB::raw('count(*) as count'))
            ->whereBetween('registration_date', [$startDate, $endDate])
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->whereNotNull('education_level')
            ->groupBy('education_level')
            ->get();

        // Employment status breakdown
        $employmentBreakdown = Customer::select('employment_status', DB::raw('count(*) as count'))
            ->whereBetween('registration_date', [$startDate, $endDate])
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->whereNotNull('employment_status')
            ->groupBy('employment_status')
            ->get();

        return response()->json([
            'summary' => [
                'total_customers' => $totalCustomers,
                'women_count' => $womenCount,
                'women_percentage' => $totalCustomers > 0 ? round(($womenCount / $totalCustomers) * 100, 2) : 0,
                'disability_count' => $disabilityCount,
                'disability_percentage' => $totalCustomers > 0 ? round(($disabilityCount / $totalCustomers) * 100, 2) : 0,
                'women_disability_count' => $womenDisabilityCount,
                'women_disability_percentage' => $totalCustomers > 0 ? round(($womenDisabilityCount / $totalCustomers) * 100, 2) : 0,
            ],
            'gender_breakdown' => $genderBreakdown,
            'education_breakdown' => $educationBreakdown,
            'employment_breakdown' => $employmentBreakdown,
        ]);
    }

    private function generateLoanReport($startDate, $endDate, $branchId)
    {
        $query = Loan::query()
            ->whereBetween('application_date', [$startDate, $endDate])
            ->with('customer');

        if ($branchId) {
            $query->whereHas('customer', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        $totalLoans = $query->count();
        $womenLoans = $query->get()->filter(function ($loan) {
            return $loan->customer->is_woman;
        })->count();
        $disabilityLoans = $query->get()->filter(function ($loan) {
            return $loan->customer->has_disability;
        })->count();

        $totalAmount = $query->sum('requested_amount');
        $womenAmount = $query->get()->filter(function ($loan) {
            return $loan->customer->is_woman;
        })->sum('requested_amount');
        $disabilityAmount = $query->get()->filter(function ($loan) {
            return $loan->customer->has_disability;
        })->sum('requested_amount');

        return response()->json([
            'summary' => [
                'total_loans' => $totalLoans,
                'women_loans' => $womenLoans,
                'women_loans_percentage' => $totalLoans > 0 ? round(($womenLoans / $totalLoans) * 100, 2) : 0,
                'disability_loans' => $disabilityLoans,
                'disability_loans_percentage' => $totalLoans > 0 ? round(($disabilityLoans / $totalLoans) * 100, 2) : 0,
                'total_amount' => $totalAmount,
                'women_amount' => $womenAmount,
                'women_amount_percentage' => $totalAmount > 0 ? round(($womenAmount / $totalAmount) * 100, 2) : 0,
                'disability_amount' => $disabilityAmount,
                'disability_amount_percentage' => $totalAmount > 0 ? round(($disabilityAmount / $totalAmount) * 100, 2) : 0,
            ]
        ]);
    }

    private function generateSavingsReport($startDate, $endDate, $branchId)
    {
        $query = SavingsAccount::query()
            ->whereBetween('opened_at', [$startDate, $endDate])
            ->with('customer');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $totalAccounts = $query->count();
        $womenAccounts = $query->get()->filter(function ($account) {
            return $account->customer->is_woman;
        })->count();
        $disabilityAccounts = $query->get()->filter(function ($account) {
            return $account->customer->has_disability;
        })->count();

        $totalBalance = $query->withCount(['transactions as balance' => function ($q) {
                $q->select(DB::raw('SUM(CASE WHEN transaction_type = "deposit" THEN amount ELSE -amount END)'));
            }])
            ->get()
            ->sum('balance');

        return response()->json([
            'summary' => [
                'total_accounts' => $totalAccounts,
                'women_accounts' => $womenAccounts,
                'women_accounts_percentage' => $totalAccounts > 0 ? round(($womenAccounts / $totalAccounts) * 100, 2) : 0,
                'disability_accounts' => $disabilityAccounts,
                'disability_accounts_percentage' => $totalAccounts > 0 ? round(($disabilityAccounts / $totalAccounts) * 100, 2) : 0,
                'total_balance' => $totalBalance,
            ]
        ]);
    }

    private function generateComprehensiveReport($startDate, $endDate, $branchId)
    {
        $customerReport = $this->generateCustomerReport($startDate, $endDate, $branchId);
        $loanReport = $this->generateLoanReport($startDate, $endDate, $branchId);
        $savingsReport = $this->generateSavingsReport($startDate, $endDate, $branchId);

        return response()->json([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'branch_id' => $branchId,
            ],
            'customer_inclusion' => json_decode($customerReport->getContent(), true),
            'loan_inclusion' => json_decode($loanReport->getContent(), true),
            'savings_inclusion' => json_decode($savingsReport->getContent(), true),
        ]);
    }

    public function exportNbeReport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $report = $this->generateComprehensiveReport(
            $request->start_date,
            $request->end_date,
            $request->branch_id
        );

        // This would generate NBE-compliant CSV/Excel format
        // For now, returning JSON that can be processed by frontend
        return $report;
    }
}
