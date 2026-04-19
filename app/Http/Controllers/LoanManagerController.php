<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoanManagerController extends Controller
{
    public function index()
    {
        $branchManager = Auth::user();
        $loanManagers = User::where('role', 'loan_manager')
            ->where('manager_id', $branchManager->id)
            ->orderBy('name')
            ->get();

        return view('manager.loan-managers', [
            'loanManagers' => $loanManagers,
        ]);
    }

    public function store(Request $request)
    {
        $branchManager = Auth::user();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $manager = User::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role' => 'loan_manager',
            'manager_id' => $branchManager->id,
            'branch_id' => $branchManager->branch_id,
        ]);

        $this->logAudit('loan_manager.created', $manager, [
            'branch_id' => $branchManager->branch_id,
        ]);

        return redirect()->back()->with('status', 'Loan manager created.');
    }
}
