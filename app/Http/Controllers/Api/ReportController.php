<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary()
    {
        $branchId = $this->branchIdForUser(Auth::user());

        $activeCustomers = DB::table('customers')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->count();

        $loanBaseQuery = DB::table('loans')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('customers.branch_id', $branchId);
            });

        $loansIssued30d = (clone $loanBaseQuery)
            ->where('application_date', '>=', now()->subDays(30)->toDateString())
            ->count();

        $totalDisbursed = (float) (clone $loanBaseQuery)
            ->whereIn('status', ['Pending', 'Approved', 'Closed'])
            ->sum('requested_amount');

        $totalCollections = (float) DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('customers.branch_id', $branchId);
            })
            ->sum('installment_amount');

        return response()->json([
            'activeCustomers' => $activeCustomers,
            'loansIssued30d' => $loansIssued30d,
            'totalDisbursed' => $totalDisbursed,
            'totalCollections' => $totalCollections,
        ]);
    }

    public function weekly()
    {
        $branchId = $this->branchIdForUser(Auth::user());

        $loanRows = DB::table('loans')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->selectRaw(
                "YEARWEEK(application_date, 1) AS weekKey,
                 COUNT(*) AS applications,
                 SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
                 COALESCE(SUM(CASE WHEN status IN ('Approved', 'Closed') THEN requested_amount ELSE 0 END), 0) AS disbursed"
            )
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('customers.branch_id', $branchId);
            })
            ->where('application_date', '>=', now()->subWeeks(8)->toDateString())
            ->groupBy('weekKey')
            ->orderByDesc('weekKey')
            ->get();

        $collectionRows = DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->selectRaw('YEARWEEK(payment_date, 1) AS weekKey, COALESCE(SUM(installment_amount), 0) AS collected')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('customers.branch_id', $branchId);
            })
            ->where('payment_date', '>=', now()->subWeeks(8)->toDateString())
            ->groupBy('weekKey')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->weekKey => (float) $row->collected]);

        $weekly = $loanRows->map(function ($row) use ($collectionRows) {
            return [
                'weekKey' => (int) $row->weekKey,
                'applications' => (int) $row->applications,
                'approved' => (int) $row->approved,
                'disbursed' => (float) $row->disbursed,
                'collected' => $collectionRows[(int) $row->weekKey] ?? 0.0,
            ];
        })->values();

        return response()->json($weekly);
    }
}
