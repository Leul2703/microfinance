<?php

namespace App\Http\Controllers;

use App\Models\SavingsAccount;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SavingsApprovalController extends Controller
{
    public function index()
    {
        $branchId = $this->branchIdForUser();

        $accounts = SavingsAccount::query()
            ->with('customer:id,full_name,phone_number,email_address')
            ->where('status', 'pending')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderByDesc('created_at')
            ->get();

        return view('manager.savings-approvals', [
            'accounts' => $accounts,
        ]);
    }

    public function approve(Request $request, SavingsAccount $account)
    {
        $payload = $request->validate([
            'approval_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureAccountInManagerBranch($account);
        $this->ensureCustomerIsReady($account);

        $account->update([
            'status' => 'active',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $payload['approval_notes'] ?? null,
        ]);

        $this->queueCustomerNotification(
            $account,
            'Your savings account request has been approved and activated.'
        );
        $this->logAudit('savings_account.approved', $account, [
            'customer_id' => $account->customer_id,
        ]);

        return redirect()->back()->with('status', 'Savings account approved.');
    }

    public function decline(Request $request, SavingsAccount $account)
    {
        $payload = $request->validate([
            'approval_notes' => ['required', 'string', 'max:2000'],
        ]);

        $this->ensureAccountInManagerBranch($account);

        $account->update([
            'status' => 'declined',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $payload['approval_notes'],
        ]);

        $this->queueCustomerNotification(
            $account,
            'Your savings account request was declined. Please contact the branch for guidance.'
        );
        $this->logAudit('savings_account.declined', $account, [
            'customer_id' => $account->customer_id,
        ]);

        return redirect()->back()->with('status', 'Savings account declined.');
    }

    /**
     * Close a savings account
     */
    public function closeAccount(Request $request, SavingsAccount $account)
    {
        $payload = $request->validate([
            'closure_reason' => ['required', 'string', 'max:2000'],
            'confirm_closure' => ['accepted'],
        ]);

        $this->ensureAccountInManagerBranch($account);

        // Ensure account is active and can be closed
        if ($account->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Only active accounts can be closed'
            ]);
        }

        // Calculate closing balance
        $closingBalance = $account->transactions()->sum('amount');

        try {
            $account->update([
                'status' => 'closed',
                'closed_by' => Auth::id(),
                'closed_at' => now(),
                'closure_reason' => $payload['closure_reason'],
                'closing_balance' => $closingBalance,
            ]);

            // Archive transaction data (already preserved in database)
            
            // Notify customer
            $this->queueCustomerNotification(
                $account,
                sprintf(
                    'Your savings account %s has been closed. Final balance: ETB %s. %s',
                    $account->account_number,
                    number_format($closingBalance, 2),
                    $payload['closure_reason']
                )
            );

            $this->logAudit('savings_account.closed', $account, [
                'customer_id' => $account->customer_id,
                'closing_balance' => $closingBalance,
                'closure_reason' => $payload['closure_reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Savings account closed successfully',
                'closing_balance' => $closingBalance
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to close account: ' . $e->getMessage()
            ]);
        }
    }

    private function ensureAccountInManagerBranch(SavingsAccount $account): void
    {
        $branchId = $this->branchIdForUser();
        if ($branchId && (int) $account->branch_id !== $branchId) {
            abort(403);
        }
    }

    private function ensureCustomerIsReady(SavingsAccount $account): void
    {
        $customer = $account->customer;
        if (!$customer || !$customer->national_id || !$customer->phone_number || !$customer->address) {
            abort(422, 'Customer KYC details are incomplete for approval.');
        }
    }

    private function queueCustomerNotification(SavingsAccount $account, string $message): void
    {
        $customer = $account->customer;
        if (!$customer) {
            return;
        }

        $recipient = $customer->phone_number ?: $customer->email_address;
        if (!$recipient) {
            return;
        }

        SmsLog::create([
            'customer_id' => $customer->id,
            'channel' => $customer->phone_number ? 'sms' : 'email',
            'recipient' => $recipient,
            'message' => $message,
            'status' => 'queued',
        ]);
    }
}
