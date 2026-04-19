<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanStatementRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoanStatementRequestController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);

        $requests = LoanStatementRequest::query()
            ->with(['loan.customer:id,full_name,branch_id', 'requester:id,name'])
            ->when($user->role !== 'admin', function ($query) use ($branchId) {
                $query->whereHas('loan.customer', function ($customerQuery) use ($branchId) {
                    $customerQuery->where('branch_id', $branchId);
                });
            })
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return view('manager.loan-statements', [
            'requests' => $requests,
        ]);
    }

    public function store(Request $request, Loan $loan)
    {
        $payload = $request->validate([
            'start_date' => ['required', 'date', 'before_or_equal:end_date'],
            'end_date' => ['required', 'date', 'before_or_equal:today'],
            'request_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureLoanVisibleToStaff($loan);

        LoanStatementRequest::create([
            'loan_id' => $loan->id,
            'requested_by' => Auth::id(),
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'request_note' => $payload['request_note'] ?? null,
        ]);

        $this->logAudit('loan_statement.requested', $loan, [
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
        ]);

        return redirect()->back()->with('status', 'Loan statement request submitted for approval.');
    }

    public function approve(Request $request, LoanStatementRequest $statementRequest)
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureReviewAccess($statementRequest);

        $statementRequest->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'review_note' => $payload['review_note'] ?? null,
            'reviewed_at' => now(),
        ]);

        $this->logAudit('loan_statement.approved', $statementRequest, [
            'loan_id' => $statementRequest->loan_id,
        ]);

        return redirect()->back()->with('status', 'Loan statement approved.');
    }

    public function decline(Request $request, LoanStatementRequest $statementRequest)
    {
        $payload = $request->validate([
            'review_note' => ['required', 'string', 'max:2000'],
        ]);

        $this->ensureReviewAccess($statementRequest);

        $statementRequest->update([
            'status' => 'declined',
            'reviewed_by' => Auth::id(),
            'review_note' => $payload['review_note'],
            'reviewed_at' => now(),
        ]);

        $this->logAudit('loan_statement.declined', $statementRequest, [
            'loan_id' => $statementRequest->loan_id,
        ]);

        return redirect()->back()->with('status', 'Loan statement declined.');
    }

    private function ensureLoanVisibleToStaff(Loan $loan): void
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);

        $sameBranch = !$branchId || ($loan->customer && (int) $loan->customer->branch_id === $branchId);
        if (!$sameBranch) {
            abort(403);
        }

        if ($user->role === 'loan_employee' && (int) $loan->created_by !== (int) $user->id) {
            abort(403);
        }
    }

    private function ensureReviewAccess(LoanStatementRequest $statementRequest): void
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);
        $requestBranchId = optional(optional($statementRequest->loan)->customer)->branch_id;

        if ($user->role !== 'admin' && $branchId && (int) $requestBranchId !== $branchId) {
            abort(403);
        }
    }
}
