<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportPageController extends Controller
{
    public function index(Request $request, ReportController $apiReportController)
    {
        $summary = $apiReportController->summary()->getData(true);
        $weeklyRows = $apiReportController->weekly()->getData(true);

        $portfolioAtRisk = $this->portfolioAtRisk();
        $netGrowth = $summary['totalDisbursed'] > 0
            ? (($summary['totalCollections'] - $summary['totalDisbursed']) / $summary['totalDisbursed']) * 100
            : 0;

        $branchRanking = DB::table('branches')
            ->leftJoin('customers', 'customers.branch_id', '=', 'branches.id')
            ->leftJoin('loans', 'loans.customer_id', '=', 'customers.id')
            ->selectRaw('branches.name, COUNT(DISTINCT customers.id) as customers_count, COUNT(DISTINCT loans.id) as loans_count, COALESCE(SUM(loans.requested_amount), 0) as portfolio_total')
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('portfolio_total')
            ->limit(5)
            ->get();

        return view('reports', [
            'summary' => $summary,
            'portfolioAtRisk' => $portfolioAtRisk,
            'netGrowth' => $netGrowth,
            'weeklyRows' => $weeklyRows,
            'branchRanking' => $branchRanking,
        ]);
    }

    private function portfolioAtRisk(): float
    {
        $overdue = (float) DB::table('loan_payment_schedules')
            ->whereIn('status', ['Pending', 'Partial'])
            ->where('due_date', '<', now()->toDateString())
            ->sum(DB::raw('amount_due - amount_paid'));

        $portfolio = (float) DB::table('loans')
            ->whereIn('status', ['Approved', 'Pending', 'Closed'])
            ->sum('requested_amount');

        return $portfolio > 0 ? ($overdue / $portfolio) * 100 : 0;
    }
}
