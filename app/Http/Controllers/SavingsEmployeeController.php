<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class SavingsEmployeeController extends Controller
{
    public function index()
    {
        $employees = User::query()
            ->where('role', 'savings_employee')
            ->where('manager_id', Auth::id())
            ->orderBy('name')
            ->get();

        return view('manager.savings-employees', [
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
            'role' => 'savings_employee',
            'manager_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
        ]);

        $this->logAudit('savings_employee.created', $employee, [
            'manager_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
        ]);

        return redirect()->back()->with('status', 'Savings employee created.');
    }
}
