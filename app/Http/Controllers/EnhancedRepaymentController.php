<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Repayment;
use App\Services\PaymentProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnhancedRepaymentController extends Controller
{
    private $paymentService;

    public function __construct(PaymentProcessingService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Show payment form with options
     */
    public function showPaymentForm($loanId)
    {
        $user = Auth::user();
        $loan = Loan::with(['customer', 'schedules'])->findOrFail($loanId);

        // Check permissions
        if ($user->role === 'customer') {
            if ($loan->customer_id !== $user->customer->id) {
                abort(403, 'You can only make payments for your own loans.');
            }
        }

        $paymentOptions = $this->paymentService->getPaymentOptions($loan);
        $paymentSummary = $this->paymentService->getPaymentSummary($loan);

        return view('payments.enhanced-form', compact('loan', 'paymentOptions', 'paymentSummary'));
    }

    /**
     * Process payment
     */
    public function processPayment(Request $request, $loanId)
    {
        $request->validate([
            'payment_type' => 'required|in:regular,advance,bulk',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|in:cash,bank_transfer,mobile_money,cheque',
            'payment_note' => 'nullable|string|max:500',
        ]);

        $loan = Loan::findOrFail($loanId);
        $amount = (float) $request->amount;
        $paymentType = $request->payment_type;
        $paymentMethod = $request->payment_method;
        $note = $request->payment_note;

        // Validate payment amount against payment type
        $paymentOptions = $this->paymentService->getPaymentOptions($loan);
        
        if (!$paymentOptions['can_pay']) {
            return response()->json(['success' => false, 'message' => $paymentOptions['message']], 400);
        }

        $maxAmount = $paymentOptions['payment_options'][$paymentType]['max_amount'] ?? 0;
        if ($amount > $maxAmount) {
            return response()->json([
                'success' => false, 
                'message' => "Maximum amount for {$paymentType} payment is ETB " . number_format($maxAmount, 2)
            ], 400);
        }

        // Process payment
        $result = $this->paymentService->processPayment($loan, $amount, $paymentType, $paymentMethod, $note);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'repayment_id' => $result['repayment_id'],
                'redirect' => route('customer.dashboard')
            ]);
        } else {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }
    }

    /**
     * Show payment history for a loan
     */
    public function showPaymentHistory($loanId)
    {
        $user = Auth::user();
        $loan = Loan::with(['customer', 'repayments.creator', 'schedules'])->findOrFail($loanId);

        // Check permissions
        if ($user->role === 'customer') {
            if ($loan->customer_id !== $user->customer->id) {
                abort(403, 'You can only view payment history for your own loans.');
            }
        }

        $paymentSummary = $this->paymentService->getPaymentSummary($loan);

        return view('payments.history', compact('loan', 'paymentSummary'));
    }

    /**
     * API endpoint to get payment options
     */
    public function getPaymentOptions($loanId)
    {
        $loan = Loan::findOrFail($loanId);
        $options = $this->paymentService->getPaymentOptions($loan);

        return response()->json($options);
    }

    /**
     * API endpoint to calculate payment allocation
     */
    public function calculatePaymentAllocation(Request $request, $loanId)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_type' => 'required|in:regular,advance,bulk',
        ]);

        $loan = Loan::with('schedules')->findOrFail($loanId);
        $amount = (float) $request->amount;
        $paymentType = $request->payment_type;

        // Get pending installments
        $pendingInstallments = $loan->schedules()
            ->whereIn('status', ['Pending', 'Partial'])
            ->orderBy('installment_number')
            ->get();

        if ($pendingInstallments->isEmpty()) {
            return response()->json(['can_process' => false, 'message' => 'No pending installments found.']);
        }

        // Calculate allocation (simplified version for preview)
        $remainingAmount = $amount;
        $coveredInstallments = [];
        $totalDue = 0;

        foreach ($pendingInstallments as $installment) {
            $remainingDue = $installment->amount_due - $installment->amount_paid;
            $totalDue += $remainingDue;

            if ($remainingAmount <= 0) {
                break;
            }

            $amountToApply = min($remainingAmount, $remainingDue);

            if ($amountToApply > 0) {
                $coveredInstallments[] = [
                    'installment_number' => $installment->installment_number,
                    'due_date' => $installment->due_date->format('M d, Y'),
                    'amount_due' => $remainingDue,
                    'amount_to_apply' => $amountToApply,
                    'remaining_after' => $remainingDue - $amountToApply
                ];

                $remainingAmount -= $amountToApply;
            }
        }

        $excessAmount = max(0, $remainingAmount);
        $canProcess = true;
        $message = '';

        // Validate based on payment type
        switch ($paymentType) {
            case 'regular':
                if (count($coveredInstallments) > 1 || $excessAmount > 0) {
                    $canProcess = false;
                    $message = 'Regular payment can only cover the current due installment.';
                }
                break;
            case 'advance':
                if (count($coveredInstallments) > 1) {
                    $canProcess = false;
                    $message = 'Advance payment can only cover one installment.';
                }
                break;
        }

        return response()->json([
            'can_process' => $canProcess,
            'message' => $message,
            'total_due' => $totalDue,
            'covered_installments' => $coveredInstallments,
            'excess_amount' => $excessAmount,
            'summary' => sprintf(
                'This payment will cover %d installment(s) with %s excess amount.',
                count($coveredInstallments),
                $excessAmount > 0 ? 'ETB ' . number_format($excessAmount, 2) : 'no'
            )
        ]);
    }

    /**
     * Show bulk payment form for multiple loans
     */
    public function showBulkPaymentForm()
    {
        $user = Auth::user();
        
        if ($user->role === 'customer') {
            $loans = Loan::with(['schedules'])
                ->where('customer_id', $user->customer->id)
                ->where('status', 'Approved')
                ->whereHas('schedules', function ($query) {
                    $query->whereIn('status', ['Pending', 'Partial']);
                })
                ->get();
        } else {
            // Staff can access loans based on permissions
            $loans = Loan::with(['customer', 'schedules'])
                ->where('status', 'Approved')
                ->whereHas('schedules', function ($query) {
                    $query->whereIn('status', ['Pending', 'Partial']);
                })
                ->get();
        }

        return view('payments.bulk-form', compact('loans'));
    }

    /**
     * Process bulk payment for multiple loans
     */
    public function processBulkPayment(Request $request)
    {
        $request->validate([
            'selected_loans' => 'required|array|min:1',
            'selected_loans.*' => 'required|exists:loans,id',
            'payment_method' => 'required|in:cash,bank_transfer,mobile_money,cheque',
            'payment_note' => 'nullable|string|max:500',
        ]);

        $selectedLoans = $request->selected_loans;
        $paymentMethod = $request->payment_method;
        $note = $request->payment_note;

        $results = [];
        $totalProcessed = 0;
        $totalFailed = 0;

        foreach ($selectedLoans as $loanId) {
            $loan = Loan::find($loanId);
            
            // Get total due for this loan
            $totalDue = $loan->schedules()
                ->whereIn('status', ['Pending', 'Partial'])
                ->sum('amount_due') - $loan->schedules()
                ->whereIn('status', ['Pending', 'Partial'])
                ->sum('amount_paid');

            if ($totalDue > 0) {
                $result = $this->paymentService->processBulkPayment($loan, $totalDue, $paymentMethod, $note);
                
                $results[] = [
                    'loan_id' => $loanId,
                    'success' => $result['success'],
                    'message' => $result['message']
                ];

                if ($result['success']) {
                    $totalProcessed++;
                } else {
                    $totalFailed++;
                }
            }
        }

        return response()->json([
            'success' => $totalFailed === 0,
            'message' => sprintf(
                'Bulk payment completed. %d processed successfully, %d failed.',
                $totalProcessed,
                $totalFailed
            ),
            'results' => $results
        ]);
    }
}
