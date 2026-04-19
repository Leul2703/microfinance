<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class BranchManagerController extends Controller
{
    public function index()
    {
        $branches = Branch::orderBy('name')->get();
        $managers = User::where('role', 'branch_manager')->with('branch')->orderBy('name')->get();

        return view('admin.branch-managers', [
            'branches' => $branches,
            'managers' => $managers,
        ]);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'branch_id' => ['required', 'exists:branches,id'],
        ]);

        $manager = User::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
            'role' => 'branch_manager',
            'branch_id' => $payload['branch_id'],
        ]);

        $this->logAudit('branch_manager.created', $manager, [
            'branch_id' => $payload['branch_id'],
        ]);

        return redirect()->back()->with('status', 'Branch manager created.');
    }

    /**
     * Update deposit limits for a branch
     */
    public function updateDepositLimits(Request $request, $branchId)
    {
        $request->validate([
            'max_deposit_limit' => ['nullable', 'numeric', 'min:0'],
            'min_deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'max_withdrawal_limit' => ['nullable', 'numeric', 'min:0'],
            'daily_transaction_limit' => ['nullable', 'numeric', 'min:0'],
            'deposit_limits_enabled' => ['nullable', 'boolean'],
        ]);

        $branch = Branch::findOrFail($branchId);
        
        $branch->update([
            'max_deposit_limit' => $request->max_deposit_limit ?? $branch->max_deposit_limit,
            'min_deposit_amount' => $request->min_deposit_amount ?? $branch->min_deposit_amount,
            'max_withdrawal_limit' => $request->max_withdrawal_limit ?? $branch->max_withdrawal_limit,
            'daily_transaction_limit' => $request->daily_transaction_limit ?? $branch->daily_transaction_limit,
            'deposit_limits_enabled' => $request->has('deposit_limits_enabled') ? $request->deposit_limits_enabled : $branch->deposit_limits_enabled,
        ]);

        $this->logAudit('branch.deposit_limits_updated', $branch, [
            'max_deposit_limit' => $branch->max_deposit_limit,
            'min_deposit_amount' => $branch->min_deposit_amount,
            'max_withdrawal_limit' => $branch->max_withdrawal_limit,
            'daily_transaction_limit' => $branch->daily_transaction_limit,
            'deposit_limits_enabled' => $branch->deposit_limits_enabled,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Deposit limits updated successfully',
            'branch' => $branch
        ]);
    }

    /**
     * Get deposit limits for a branch
     */
    public function getDepositLimits($branchId)
    {
        $branch = Branch::findOrFail($branchId);
        
        return response()->json([
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'max_deposit_limit' => $branch->max_deposit_limit,
            'min_deposit_amount' => $branch->min_deposit_amount,
            'max_withdrawal_limit' => $branch->max_withdrawal_limit,
            'daily_transaction_limit' => $branch->daily_transaction_limit,
            'deposit_limits_enabled' => $branch->deposit_limits_enabled,
        ]);
    }

    /**
     * Soft delete a user account
     */
    public function deleteUser(Request $request, User $user)
    {
        // Prevent deletion of admin accounts
        if ($user->role === 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete admin accounts'
            ]);
        }

        // Prevent deletion of own account
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account'
            ]);
        }

        try {
            $user->delete();

            $this->logAudit('user.deleted', $user, [
                'user_role' => $user->role,
                'user_email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User account deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Restore a soft-deleted user account
     */
    public function restoreUser(Request $request, $userId)
    {
        try {
            $user = User::withTrashed()->find($userId);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ]);
            }

            $user->restore();

            $this->logAudit('user.restored', $user, [
                'user_role' => $user->role,
                'user_email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User account restored successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore user: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Permanently delete a user account
     */
    public function forceDeleteUser(Request $request, $userId)
    {
        // Require confirmation
        if (!$request->confirm_permanent_delete) {
            return response()->json([
                'success' => false,
                'message' => 'Permanent deletion requires confirmation'
            ]);
        }

        try {
            $user = User::withTrashed()->find($userId);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ]);
            }

            // Prevent permanent deletion of admin accounts
            if ($user->role === 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot permanently delete admin accounts'
                ]);
            }

            // Delete associated customer data if exists
            if ($user->customer) {
                $user->customer->delete();
            }

            // Permanently delete user
            $user->forceDelete();

            $this->logAudit('user.force_deleted', null, [
                'user_id' => $userId,
                'user_role' => $user->role,
                'user_email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User account permanently deleted'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete user: ' . $e->getMessage()
            ]);
        }
    }
}
