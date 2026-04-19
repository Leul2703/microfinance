<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\BranchFinancialService;
use Illuminate\Http\Request;

class BranchFinancialController extends Controller
{
    private $financialService;

    public function __construct(BranchFinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * Display branch financial view
     */
    public function index()
    {
        $branches = Branch::orderBy('name')->get();
        $selectedBranchId = request('branch_id');
        $startDate = request('start_date');
        $endDate = request('end_date');

        $financialData = null;
        if ($selectedBranchId) {
            $financialData = $this->financialService->getBranchFinancialSummary(
                $selectedBranchId,
                $startDate ? \Carbon\Carbon::parse($startDate) : null,
                $endDate ? \Carbon\Carbon::parse($endDate) : null
            );
        }

        return view('admin.branch-financial', [
            'branches' => $branches,
            'selectedBranchId' => $selectedBranchId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'financialData' => $financialData,
        ]);
    }

    /**
     * Get branch financial data as JSON
     */
    public function getBranchData(Request $request)
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $branchId = $request->branch_id;
        $startDate = $request->start_date ? \Carbon\Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? \Carbon\Carbon::parse($request->end_date) : null;

        $financialData = $this->financialService->getBranchFinancialSummary(
            $branchId,
            $startDate,
            $endDate
        );

        return response()->json($financialData);
    }

    /**
     * Get branch comparison data
     */
    public function getComparison(Request $request)
    {
        $branchIds = $request->branch_ids ? explode(',', $request->branch_ids) : null;
        $startDate = $request->start_date ? \Carbon\Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? \Carbon\Carbon::parse($request->end_date) : null;

        $comparison = $this->financialService->getBranchComparison($branchIds, $startDate, $endDate);

        return response()->json($comparison);
    }

    /**
     * Get branch trend data
     */
    public function getTrends(Request $request, $branchId)
    {
        $months = $request->months ?? 12;

        $trends = $this->financialService->getBranchTrends($branchId, $months);

        return response()->json($trends);
    }

    /**
     * Export branch financial report
     */
    public function exportReport(Request $request)
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'format' => ['required', 'in:csv,xlsx'],
        ]);

        $branchId = $request->branch_id;
        $startDate = $request->start_date ? \Carbon\Carbon::parse($request->start_date) : null;
        $endDate = $request->end_date ? \Carbon\Carbon::parse($request->end_date) : null;
        $format = $request->format;

        $financialData = $this->financialService->getBranchFinancialSummary(
            $branchId,
            $startDate,
            $endDate
        );

        $branch = Branch::find($branchId);

        if ($format === 'csv') {
            return $this->exportCsv($branch, $financialData);
        }

        return response()->json(['message' => 'Excel export not implemented yet']);
    }

    /**
     * Export data as CSV
     */
    private function exportCsv($branch, $data)
    {
        $csv = fopen('php://temp', 'r+');

        // Write header
        fputcsv($csv, ['Branch Financial Report']);
        fputcsv($csv, ['Branch', $branch->name]);
        fputcsv($csv, ['Period', $data['period']['start_date'] . ' to ' . $data['period']['end_date']]);
        fputcsv($csv, []);

        // Loan metrics
        fputcsv($csv, ['Loan Metrics']);
        fputcsv($csv, ['Total Loans', $data['loans']['total_loans']]);
        fputcsv($csv, ['Active Loans', $data['loans']['active_loans']]);
        fputcsv($csv, ['Total Portfolio', $data['loans']['total_loan_portfolio']]);
        fputcsv($csv, ['New Loans (Period)', $data['loans']['new_loans_period']]);
        fputcsv($csv, ['New Loan Amount (Period)', $data['loans']['new_loan_amount_period']]);
        fputcsv($csv, []);

        // Savings metrics
        fputcsv($csv, ['Savings Metrics']);
        fputcsv($csv, ['Total Accounts', $data['savings']['total_accounts']]);
        fputcsv($csv, ['Active Accounts', $data['savings']['active_accounts']]);
        fputcsv($csv, ['Total Balance', $data['savings']['total_balance']]);
        fputcsv($csv, ['Deposits (Period)', $data['savings']['deposits_period']]);
        fputcsv($csv, ['Withdrawals (Period)', $data['savings']['withdrawals_period']]);
        fputcsv($csv, []);

        // Repayment metrics
        fputcsv($csv, ['Repayment Metrics']);
        fputcsv($csv, ['Total Collected (Period)', $data['repayments']['total_collected_period']]);
        fputcsv($csv, ['Total Transactions (Period)', $data['repayments']['total_transactions_period']]);
        fputcsv($csv, ['Overdue Amount', $data['repayments']['overdue_amount']]);
        fputcsv($csv, ['Collection Rate', number_format($data['repayments']['collection_rate'], 2) . '%']);
        fputcsv($csv, []);

        // Customer metrics
        fputcsv($csv, ['Customer Metrics']);
        fputcsv($csv, ['Total Customers', $data['customers']['total_customers']]);
        fputcsv($csv, ['New Customers (Period)', $data['customers']['new_customers_period']]);
        fputcsv($csv, ['Women Percentage', number_format($data['customers']['women_percentage'], 2) . '%']);
        fputcsv($csv, ['Disabled Percentage', number_format($data['customers']['disabled_percentage'], 2) . '%']);
        fputcsv($csv, []);

        // Summary
        fputcsv($csv, ['Overall Summary']);
        fputcsv($csv, ['Total Assets', $data['summary']['total_assets']]);
        fputcsv($csv, ['Total Liabilities', $data['summary']['total_liabilities']]);
        fputcsv($csv, ['Net Period Income', $data['summary']['net_period_income']]);
        fputcsv($csv, ['Period Growth', $data['summary']['period_growth']]);

        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        $filename = "branch_financial_{$branch->name}_{$data['period']['start_date']}_to_{$data['period']['end_date']}.csv";

        return response($csvContent)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
