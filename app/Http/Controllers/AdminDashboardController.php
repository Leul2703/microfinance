<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanPaymentSchedule;
use App\Models\Repayment;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $activeCustomers = Customer::count();
        $portfolioValue = (float) Loan::where('status', 'Approved')->sum('requested_amount');
        $loansIssuedMonth = Loan::whereBetween('application_date', [$monthStart, $monthEnd])->count();

        $dueThisMonth = (float) LoanPaymentSchedule::whereBetween('due_date', [$monthStart, $monthEnd])->sum('amount_due');
        $paidThisMonth = (float) LoanPaymentSchedule::whereBetween('due_date', [$monthStart, $monthEnd])->sum('amount_paid');
        $repaymentRate = $dueThisMonth > 0 ? round(($paidThisMonth / $dueThisMonth) * 100, 2) : 0;

        $pendingApprovals = Loan::where('status', 'Pending')->count();
        $overdueInstallments = LoanPaymentSchedule::where('due_date', '<', $today)
            ->whereIn('status', ['Pending', 'Partial', 'Overdue'])
            ->count();

        $recentLoans = Loan::with('customer')
            ->latest('application_date')
            ->limit(8)
            ->get();

        $recentRepayments = Repayment::with(['loan.customer'])
            ->latest('payment_date')
            ->limit(6)
            ->get();

        return view('dashboard', [
            'activeCustomers' => $activeCustomers,
            'portfolioValue' => $portfolioValue,
            'loansIssuedMonth' => $loansIssuedMonth,
            'repaymentRate' => $repaymentRate,
            'pendingApprovals' => $pendingApprovals,
            'overdueInstallments' => $overdueInstallments,
            'recentLoans' => $recentLoans,
            'recentRepayments' => $recentRepayments,
            'monthLabel' => $monthStart->format('F Y'),
        ]);
    }
}

