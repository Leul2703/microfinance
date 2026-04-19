<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavingsStaffController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);

        $accounts = SavingsAccount::query()
            ->with('customer:id,full_name')
            ->withSum('transactions', 'amount')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderByDesc('created_at')
            ->get();

        return view('staff.savings-dashboard', [
            'accounts' => $accounts,
        ]);
    }

    public function storeAccount(Request $request)
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);

        $payload = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'savings_type' => ['required', 'in:regular,fixed,voluntary,compulsory'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'opened_at' => ['nullable', 'date'],
            'initial_deposit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = Customer::findOrFail($payload['customer_id']);
        $account = DB::transaction(function () use ($payload, $user, $branchId, $customer) {
            $openedAt = $payload['opened_at'] ?? now()->toDateString();
            $termMonths = $payload['savings_type'] === 'fixed' ? ($payload['term_months'] ?? 0) : null;
            $maturityDate = null;
            if ($payload['savings_type'] === 'fixed' && $termMonths) {
                $maturityDate = now()->parse($openedAt)->addMonths($termMonths)->toDateString();
            }

            $account = SavingsAccount::create([
                'account_number' => $this->generateAccountNumber(),
                'customer_id' => $customer->id,
                'branch_id' => $branchId ?? $customer->branch_id,
                'created_by' => $user->id,
                'manager_id' => $user->manager_id,
                'status' => 'pending',
                'savings_type' => $payload['savings_type'],
                'interest_rate' => $payload['interest_rate'] ?? 5.00,
                'term_months' => $termMonths,
                'opened_at' => $openedAt,
                'maturity_date' => $maturityDate,
            ]);

            if (!empty($payload['initial_deposit'])) {
                SavingsTransaction::create([
                    'savings_account_id' => $account->id,
                    'type' => 'deposit',
                    'amount' => $payload['initial_deposit'],
                    'created_by' => $user->id,
                    'posted_at' => now()->toDateString(),
                    'reference' => 'Initial deposit',
                ]);
            }

            return $account;
        });

        $this->logAudit('savings_account.created', $account, [
            'customer_id' => $customer->id,
            'status' => $account->status,
        ]);

        return redirect()->back()->with('status', "Savings account {$account->account_number} submitted for approval.");
    }

    public function deposit(Request $request)
    {
        $payload = $request->validate([
            'savings_account_id' => ['required', 'exists:savings_accounts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'posted_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $account = SavingsAccount::findOrFail($payload['savings_account_id']);
        $this->ensureActiveAccountAccess($account);

        SavingsTransaction::create([
            'savings_account_id' => $account->id,
            'type' => 'deposit',
            'amount' => $payload['amount'],
            'created_by' => Auth::id(),
            'posted_at' => $payload['posted_at'] ?? now()->toDateString(),
            'reference' => $payload['reference'],
        ]);

        $this->logAudit('savings.deposit_recorded', $account, [
            'amount' => $payload['amount'],
        ]);

        return redirect()->back()->with('status', 'Deposit recorded.');
    }

    public function withdraw(Request $request)
    {
        $payload = $request->validate([
            'savings_account_id' => ['required', 'exists:savings_accounts,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'posted_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $account = SavingsAccount::withSum('transactions', 'amount')->findOrFail($payload['savings_account_id']);
        $this->ensureActiveAccountAccess($account);

        $balance = (float) ($account->transactions_sum_amount ?? 0);
        if ($balance < (float) $payload['amount']) {
            return redirect()->back()->with('status', 'Insufficient balance for this withdrawal.');
        }

        SavingsTransaction::create([
            'savings_account_id' => $account->id,
            'type' => 'withdrawal',
            'amount' => -1 * abs($payload['amount']),
            'created_by' => Auth::id(),
            'posted_at' => $payload['posted_at'] ?? now()->toDateString(),
            'reference' => $payload['reference'],
        ]);

        $this->logAudit('savings.withdrawal_recorded', $account, [
            'amount' => $payload['amount'],
        ]);

        return redirect()->back()->with('status', 'Withdrawal recorded.');
    }

    public function applyInterest(Request $request)
    {
        $request->validate([
            'posted_at' => ['nullable', 'date'],
        ]);

        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);
        $postedAt = $request->input('posted_at') ?? now()->toDateString();

        $accounts = SavingsAccount::query()
            ->withSum('transactions', 'amount')
            ->where('status', 'active')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->get();

        $applied = 0;
        foreach ($accounts as $account) {
            $balance = (float) ($account->transactions_sum_amount ?? 0);
            if ($balance <= 0) {
                continue;
            }

            $interestAmount = round($balance * ((float) $account->interest_rate / 100) / 12, 2);
            if ($interestAmount <= 0) {
                continue;
            }

            SavingsTransaction::create([
                'savings_account_id' => $account->id,
                'type' => 'interest',
                'amount' => $interestAmount,
                'created_by' => $user->id,
                'posted_at' => $postedAt,
                'reference' => 'Monthly interest',
            ]);

            $account->update([
                'last_interest_applied_at' => $postedAt,
            ]);

            $applied++;
        }

        $this->logAudit('savings.interest_applied', null, [
            'accounts_count' => $applied,
            'posted_at' => $postedAt,
        ]);

        return redirect()->back()->with('status', "Interest applied to {$applied} account(s).");
    }

    private function generateAccountNumber(): string
    {
        $prefix = 'SAV-' . now()->format('Ymd');

        do {
            $candidate = $prefix . '-' . Str::upper(Str::random(6));
        } while (SavingsAccount::where('account_number', $candidate)->exists());

        return $candidate;
    }

    private function ensureActiveAccountAccess(SavingsAccount $account): void
    {
        $branchId = $this->branchIdForUser();
        if ($branchId && (int) $account->branch_id !== $branchId) {
            abort(403);
        }

        if ($account->status !== 'active') {
            abort(422, 'Only active savings accounts can accept transactions.');
        }
    }
}
