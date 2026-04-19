<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanPaymentSchedule;
use App\Models\SavingsAccount;
use App\Models\AuditLog;

class AdminComplianceController extends Controller
{
    public function index()
    {
        $totalLoans = Loan::count();
        $highValueLoans = Loan::where('requested_amount', '>', 100000)->count();
        $overdueInstallments = LoanPaymentSchedule::whereIn('status', ['Pending', 'Partial'])
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $loanProducts = Loan::query()
            ->selectRaw('loan_product, COUNT(*) as total')
            ->groupBy('loan_product')
            ->orderByDesc('total')
            ->get();

        $savingsTypes = SavingsAccount::query()
            ->selectRaw('savings_type, COUNT(*) as total')
            ->groupBy('savings_type')
            ->orderByDesc('total')
            ->get();

        $branchCounts = Branch::withCount(['customers', 'loans'])
            ->orderBy('name')
            ->get();

        $recentAudits = AuditLog::query()
            ->with('user:id,name')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('admin.compliance', [
            'totalLoans' => $totalLoans,
            'highValueLoans' => $highValueLoans,
            'overdueInstallments' => $overdueInstallments,
            'loanProducts' => $loanProducts,
            'savingsTypes' => $savingsTypes,
            'branchCounts' => $branchCounts,
            'recentAudits' => $recentAudits,
        ]);
    }
}
