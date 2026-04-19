<?php

namespace App\Http\Controllers;

use App\Models\BalanceUpdateRequest;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BalanceUpdateRequestController extends Controller
{
    /**
     * Customer submits balance update request
     */
    public function store(Request $request)
    {
        $payload = $request->validate([
            'account_type' => ['required', 'in:loan,savings'],
            'account_id' => ['required', 'integer'],
            'current_balance' => ['required', 'numeric'],
            'requested_balance' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:2000'],
            'supporting_document' => ['nullable', 'string'],
        ]);

        $customer = Auth::user()->customer;
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        // Verify account belongs to customer
        if ($payload['account_type'] === 'loan') {
            $account = Loan::where('id', $payload['account_id'])
                ->where('customer_id', $customer->id)
                ->first();
        } else {
            $account = SavingsAccount::where('id', $payload['account_id'])
                ->where('customer_id', $customer->id)
                ->first();
        }

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Account not found or does not belong to you'], 404);
        }

        // Calculate adjustment amount
        $adjustmentAmount = $payload['requested_balance'] - $payload['current_balance'];

        try {
            $request = BalanceUpdateRequest::create([
                'customer_id' => $customer->id,
                'account_type' => $payload['account_type'],
                'account_id' => $payload['account_id'],
                'current_balance' => $payload['current_balance'],
                'requested_balance' => $payload['requested_balance'],
                'adjustment_amount' => $adjustmentAmount,
                'reason' => $payload['reason'],
                'supporting_document' => $payload['supporting_document'] ?? null,
                'status' => 'pending',
                'created_by' => Auth::id(),
            ]);

            $this->logAudit('balance_update_request.created', $request, [
                'customer_id' => $customer->id,
                'account_type' => $payload['account_type'],
                'adjustment_amount' => $adjustmentAmount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Balance update request submitted successfully',
                'request_id' => $request->id
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit request: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Manager reviews balance update request
     */
    public function index()
    {
        $branchId = $this->branchIdForCurrentUser();
        
        $requests = BalanceUpdateRequest::with(['customer', 'loan', 'savingsAccount'])
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('customer', function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId);
                });
            })
            ->pending()
            ->orderByDesc('created_at')
            ->get();

        return view('manager.balance-requests', [
            'requests' => $requests,
        ]);
    }

    /**
     * Approve balance update request
     */
    public function approve(Request $request, $requestId)
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $balanceRequest = BalanceUpdateRequest::with(['customer', 'loan', 'savingsAccount'])
            ->findOrFail($requestId);

        $this->ensureBranchAccess($balanceRequest);

        try {
            DB::beginTransaction();

            // Update account balance
            if ($balanceRequest->account_type === 'loan') {
                $loan = $balanceRequest->loan;
                if ($loan) {
                    // For loans, we might adjust the closing balance or add a manual adjustment
                    // This is a simplified approach - in reality, you'd want more sophisticated logic
                    $loan->update([
                        'closing_balance' => $balanceRequest->requested_balance,
                    ]);
                }
            } else {
                $savingsAccount = $balanceRequest->savingsAccount;
                if ($savingsAccount) {
                    // For savings, we'd typically add a transaction
                    // This is a simplified approach
                    $savingsAccount->update([
                        'balance' => $balanceRequest->requested_balance,
                    ]);
                }
            }

            // Update request status
            $balanceRequest->update([
                'status' => 'approved',
                'reviewed_by' => Auth::id(),
                'review_note' => $payload['review_note'] ?? null,
                'reviewed_at' => now(),
            ]);

            DB::commit();

            $this->logAudit('balance_update_request.approved', $balanceRequest, [
                'customer_id' => $balanceRequest->customer_id,
                'adjustment_amount' => $balanceRequest->adjustment_amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Balance update approved successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve request: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Reject balance update request
     */
    public function reject(Request $request, $requestId)
    {
        $payload = $request->validate([
            'review_note' => ['required', 'string', 'max:2000'],
        ]);

        $balanceRequest = BalanceUpdateRequest::findOrFail($requestId);

        $this->ensureBranchAccess($balanceRequest);

        try {
            $balanceRequest->update([
                'status' => 'rejected',
                'reviewed_by' => Auth::id(),
                'review_note' => $payload['review_note'],
                'reviewed_at' => now(),
            ]);

            $this->logAudit('balance_update_request.rejected', $balanceRequest, [
                'customer_id' => $balanceRequest->customer_id,
                'reason' => $payload['review_note'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Balance update request rejected'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject request: ' . $e->getMessage()
            ]);
        }
    }

    private function ensureBranchAccess(BalanceUpdateRequest $request): void
    {
        $branchId = $this->branchIdForCurrentUser();
        if ($branchId && (int) $request->customer->branch_id !== $branchId) {
            abort(403);
        }
    }

    private function branchIdForCurrentUser()
    {
        $user = Auth::user();
        return $user->branch_id ?? null;
    }
}
