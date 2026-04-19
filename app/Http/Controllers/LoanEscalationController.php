<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanEscalation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LoanEscalationController extends Controller
{
    public function create($loanId)
    {
        $loan = Loan::with(['customer', 'creator'])
            ->findOrFail($loanId);

        // Check if loan can be escalated
        if ($loan->requested_amount <= 100000) {
            return redirect()->back()
                ->with('error', 'This loan amount does not require CEO escalation.');
        }

        if ($loan->status !== 'Pending') {
            return redirect()->back()
                ->with('error', 'Only pending loans can be escalated.');
        }

        // Check if already escalated
        if ($loan->latestEscalation()) {
            return redirect()->back()
                ->with('error', 'This loan has already been escalated.');
        }

        return view('manager.escalate-loan', compact('loan'));
    }

    public function store(Request $request, $loanId)
    {
        $request->validate([
            'recommendation_note' => 'required|string|max:1000',
        ]);

        $loan = Loan::findOrFail($loanId);

        // Validate escalation conditions
        if ($loan->requested_amount <= 100000) {
            return response()->json(['message' => 'Loan amount does not require CEO escalation.'], 400);
        }

        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending loans can be escalated.'], 400);
        }

        if ($loan->latestEscalation()) {
            return response()->json(['message' => 'Loan already escalated.'], 400);
        }

        DB::beginTransaction();
        try {
            // Create escalation record
            $escalation = LoanEscalation::create([
                'loan_id' => $loan->id,
                'requested_by' => Auth::id(),
                'recommendation_note' => $request->recommendation_note,
                'status' => 'pending_ceo_review',
                'escalated_at' => now(),
            ]);

            // Update loan status to indicate escalation
            $loan->update([
                'status' => 'Pending',
                'approval_role' => 'head_ceo',
            ]);

            // Log audit
            $this->logAudit('loan.escalated_to_ceo', $loan, [
                'escalation_id' => $escalation->id,
                'recommendation_note' => $request->recommendation_note,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Loan escalated to Head CEO successfully.',
                'redirect' => route('manager.loans.approvals')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error escalating loan: ' . $e->getMessage()], 500);
        }
    }

    public function index()
    {
        $user = Auth::user();
        
        $query = LoanEscalation::with(['loan.customer', 'requester', 'reviewer'])
            ->latest('escalated_at');

        // If branch manager, only show escalations from their branch
        if ($user->role === 'branch_manager') {
            $branchId = $this->getBranchIdForUser($user);
            $query->whereHas('loan.customer', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        $escalations = $query->get();

        return view('manager.escalations', compact('escalations'));
    }

    public function show($escalationId)
    {
        $escalation = LoanEscalation::with(['loan.customer', 'loan.creator', 'requester', 'reviewer'])
            ->findOrFail($escalationId);

        // Check permissions
        $user = Auth::user();
        if ($user->role === 'branch_manager') {
            $branchId = $this->getBranchIdForUser($user);
            if ($escalation->loan->customer->branch_id !== $branchId) {
                return redirect()->back()
                    ->with('error', 'You do not have permission to view this escalation.');
            }
        }

        return view('manager.escalation-detail', compact('escalation'));
    }

    private function getBranchIdForUser($user)
    {
        // This method should be implemented based on your user-branch relationship
        // For now, assuming users have a branch_id field or relationship
        return $user->branch_id ?? null;
    }

    protected function logAudit(string $action, $auditable = null, array $metadata = []): void
    {
        if (class_exists('\App\Models\AuditLog')) {
            \App\Models\AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'model_type' => $auditable ? get_class($auditable) : null,
                'model_id' => $auditable ? $auditable->id : null,
                'data' => json_encode($metadata),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
    }
}
