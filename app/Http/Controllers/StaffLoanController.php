<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanStatementRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class StaffLoanController extends Controller
{
    public function create()
    {
        return view('staff.loan-create');
    }

    public function index()
    {
        $user = Auth::user();
        $query = Loan::with('customer:id,full_name', 'documents');

        if ($user->role === 'loan_manager') {
            $employeeIds = User::where('manager_id', $user->id)->pluck('id')->all();
            $query->whereIn('created_by', array_merge([$user->id], $employeeIds));
        } else {
            $query->where('created_by', $user->id);
        }

        $loans = $query->orderByDesc('id')->get();

        $statementRequests = LoanStatementRequest::query()
            ->whereIn('loan_id', $loans->pluck('id'))
            ->latest('id')
            ->get()
            ->groupBy('loan_id');

        return view('staff.loan-list', [
            'loans' => $loans,
            'statementRequests' => $statementRequests,
        ]);
    }
}
