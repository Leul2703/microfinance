<?php

namespace App\Http\Controllers;

use App\Services\InterestCalculationService;
use App\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InterestCalculationController extends Controller
{
    private $interestService;

    public function __construct(InterestCalculationService $interestService)
    {
        $this->interestService = $interestService;
    }

    /**
     * Display interest calculation interface
     */
    public function index()
    {
        $accounts = SavingsAccount::where('status', 'active')
            ->with('customer')
            ->when(Auth::user()->branch_id, function ($query, $branchId) {
                $query->where('branch_id', $branchId);
            })
            ->latest()
            ->get();

        return view('manager.interest-calculation', compact('accounts'));
    }

    /**
     * Calculate interest for a specific account
     */
    public function calculateAccountInterest(Request $request)
    {
        $request->validate([
            'account_id' => ['required', 'exists:savings_accounts,id'],
            'calculation_date' => ['nullable', 'date'],
        ]);

        $result = $this->interestService->calculateInterest(
            $request->account_id,
            $request->calculation_date
        );

        return response()->json($result);
    }

    /**
     * Calculate compound interest
     */
    public function calculateCompoundInterest(Request $request)
    {
        $request->validate([
            'principal' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'time' => ['required', 'numeric', 'min:0'],
            'frequency' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->interestService->calculateCompoundInterest(
            $request->principal,
            $request->rate,
            $request->time,
            $request->frequency ?? 1
        );

        return response()->json($result);
    }

    /**
     * Calculate interest for multiple accounts
     */
    public function calculateBulkInterest(Request $request)
    {
        $request->validate([
            'account_ids' => ['required', 'array'],
            'account_ids.*' => ['exists:savings_accounts,id'],
            'calculation_date' => ['nullable', 'date'],
        ]);

        $result = $this->interestService->calculateBulkInterest(
            $request->account_ids,
            $request->calculation_date
        );

        return response()->json($result);
    }

    /**
     * Apply calculated interest to account
     */
    public function applyInterest(Request $request)
    {
        $request->validate([
            'account_id' => ['required', 'exists:savings_accounts,id'],
            'interest_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirm' => ['accepted'],
        ]);

        $result = $this->interestService->applyInterestToAccount(
            $request->account_id,
            $request->interest_amount,
            Auth::id(),
            $request->notes
        );

        $this->logAudit('interest.applied', SavingsAccount::find($request->account_id), [
            'interest_amount' => $request->interest_amount,
            'applied_by' => Auth::id(),
        ]);

        return response()->json($result);
    }

    /**
     * Calculate interest projection
     */
    public function calculateProjection(Request $request)
    {
        $request->validate([
            'account_id' => ['required', 'exists:savings_accounts,id'],
            'months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $result = $this->interestService->calculateInterestProjection(
            $request->account_id,
            $request->months ?? 12
        );

        return response()->json($result);
    }

    /**
     * Compare interest calculation methods
     */
    public function compareMethods(Request $request)
    {
        $request->validate([
            'principal' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'time' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $this->interestService->compareInterestMethods(
            $request->principal,
            $request->rate,
            $request->time
        );

        return response()->json($result);
    }

    /**
     * Calculate loan interest
     */
    public function calculateLoanInterest(Request $request)
    {
        $request->validate([
            'principal' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'term_months' => ['required', 'integer', 'min:1'],
            'repayment_frequency' => ['nullable', 'in:monthly,weekly,daily'],
        ]);

        $result = $this->interestService->calculateLoanInterest(
            $request->principal,
            $request->rate,
            $request->term_months,
            $request->repayment_frequency ?? 'monthly'
        );

        return response()->json($result);
    }

    /**
     * Get branch interest summary
     */
    public function getBranchSummary(Request $request)
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $result = $this->interestService->getBranchInterestSummary(
            $request->branch_id,
            $request->start_date,
            $request->end_date
        );

        return response()->json($result);
    }

    /**
     * Quick interest calculator
     */
    public function quickCalculator()
    {
        return view('manager.interest-calculator');
    }
}
