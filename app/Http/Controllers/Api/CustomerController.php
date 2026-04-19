<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index()
    {
        $user = request()->user();
        $branchId = $this->branchIdForUser($user);

        return Customer::with('branch:id,name')
            ->when($user && $user->role !== 'admin', function ($query) use ($user, $branchId) {
                if ($user->role === 'customer' && $user->customer) {
                    $query->where('id', $user->customer->id);
                    return;
                }

                if ($branchId) {
                    $query->where('branch_id', $branchId);
                }
            })
            ->latest('id')
            ->get()
            ->map(function (Customer $customer) {
                return [
                    'id' => $customer->id,
                    'fullName' => $customer->full_name,
                    'nationalId' => $customer->national_id,
                    'phoneNumber' => $customer->phone_number,
                    'branch' => optional($customer->branch)->name,
                    'registrationDate' => optional($customer->registration_date)->toDateString(),
                ];
            });
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $payload = $request->validate([
            'fullName' => ['required', 'string', 'max:150'],
            'nationalId' => ['required', 'string', 'max:60', 'unique:customers,national_id'],
            'phoneNumber' => ['required', 'string', 'max:30'],
            'emailAddress' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'dateOfBirth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['Female', 'Male', 'Other'])],
            'occupation' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string'],
            'branch' => ['required', 'string', 'exists:branches,name'],
            'registrationDate' => ['required', 'date'],
        ]);

        if ($user && in_array($user->role, ['savings_employee', 'savings_manager'], true) && $user->branch_id) {
            $branch = Branch::findOrFail($user->branch_id);
        } else {
            $branch = Branch::where('name', $payload['branch'])->firstOrFail();
        }

        $customer = DB::transaction(function () use ($payload, $branch) {
            $user = User::create([
                'name' => $payload['fullName'],
                'email' => $payload['emailAddress'],
                'password' => Hash::make($payload['password']),
                'role' => 'customer',
            ]);

            return Customer::create([
                'user_id' => $user->id,
                'full_name' => $payload['fullName'],
                'national_id' => $payload['nationalId'],
                'phone_number' => $payload['phoneNumber'],
                'email_address' => $payload['emailAddress'],
                'date_of_birth' => $payload['dateOfBirth'] ?? null,
                'gender' => $payload['gender'] ?? null,
                'occupation' => $payload['occupation'] ?? null,
                'address' => $payload['address'] ?? null,
                'branch_id' => $branch->id,
                'registration_date' => $payload['registrationDate'],
            ]);
        });

        $this->logAudit('customer.created', $customer, [
            'branch_id' => $branch->id,
            'user_id' => $customer->user_id,
        ]);

        return response()->json([
            'id' => $customer->id,
            'message' => 'Customer registered successfully.',
        ], 201);
    }
}
