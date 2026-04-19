<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoanEmployeeController extends Controller
{
    public function index()
    {
        $employees = User::query()
            ->where('role', 'loan_employee')
            ->where('manager_id', Auth::id())
            ->orderBy('name')
            ->get();

        return view('manager.employees', [
            'employees' => $employees,
        ]);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $employee = User::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role' => 'loan_employee',
            'manager_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
        ]);

        $this->logAudit('loan_employee.created', $employee, [
            'manager_id' => Auth::id(),
        ]);

        return redirect()->back()->with('status', 'Loan employee created.');
    }
}
