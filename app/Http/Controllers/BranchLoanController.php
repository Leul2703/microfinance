<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use Illuminate\Support\Facades\Auth;

class BranchLoanController extends Controller
{
    public function index()
    {
        $branchManager = Auth::user();
        $branchId = $branchManager->branch_id;

        $loans = Loan::with('customer:id,full_name,branch_id')
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('customer', function ($customerQuery) use ($branchId) {
                    $customerQuery->where('branch_id', $branchId);
                });
            })
            ->orderByDesc('id')
            ->get();

        return view('manager.branch-loans', [
            'loans' => $loans,
        ]);
    }
}
