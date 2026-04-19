<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPaymentSchedule;
use App\Models\Repayment;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentProcessingService
{
    private $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Process regular payment (current due installment only)
     */
    public function processRegularPayment(Loan $loan, $amount, $paymentMethod = 'cash', $note = null)
    {
        return $this->processPayment($loan, $amount, 'regular', $paymentMethod, $note);
    }

    /**
     * Process advance payment (pay current installment early)
     */
    public function processAdvancePayment(Loan $loan, $amount, $paymentMethod = 'cash', $note = null)
    {
        return $this->processPayment($loan, $amount, 'advance', $paymentMethod, $note);
    }

    /**
     * Process bulk payment (pay multiple installments at once)
     */
    public function processBulkPayment(Loan $loan, $amount, $paymentMethod = 'cash', $note = null)
    {
        return $this->processPayment($loan, $amount, 'bulk', $paymentMethod, $note);
    }

    /**
     * Main payment processing logic
     */
    private function processPayment(Loan $loan, $amount, $paymentType, $paymentMethod, $note)
    {
        DB::beginTransaction();
        try {
            // Get pending installments
            $pendingInstallments = $loan->schedules()
                ->whereIn('status', ['Pending', 'Partial'])
                ->orderBy('installment_number')
                ->get();

            if ($pendingInstallments->isEmpty()) {
                throw new \Exception('No pending installments found for this loan.');
            }

            $paymentResult = $this->calculatePaymentAllocation($amount, $pendingInstallments, $paymentType);
            
            if (!$paymentResult['canProcess']) {
                throw new \Exception($paymentResult['message']);
            }

            // Create repayment record
            $repayment = Repayment::create([
                'loan_id' => $loan->id,
                'installment_amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_date' => now(),
                'payment_type' => $paymentType,
                'payment_note' => $note,
                'installments_covered' => json_encode($paymentResult['coveredInstallments']),
                'excess_amount' => $paymentResult['excessAmount'],
                'created_by' => auth()->id(),
            ]);

            // Update payment schedules
            foreach ($paymentResult['coveredInstallments'] as $installmentUpdate) {
                $schedule = LoanPaymentSchedule::find($installmentUpdate['id']);
                
                if ($schedule) {
                    $schedule->amount_paid += $installmentUpdate['amount_applied'];
                    
                    if ($schedule->amount_paid >= $schedule->amount_due) {
                        $schedule->status = 'Paid';
                        $schedule->paid_at = now();
                    } else {
                        $schedule->status = 'Partial';
                    }
                    
                    $schedule->save();
                }
            }

            // Update loan next due date
            $this->updateLoanNextDueDate($loan);

            // Log audit
            $this->logAudit('payment.processed', $repayment, [
                'payment_type' => $paymentType,
                'installments_covered' => count($paymentResult['coveredInstallments']),
                'excess_amount' => $paymentResult['excessAmount'],
            ]);

            DB::commit();

            // Send notification
            $installmentNumber = $paymentResult['coveredInstallments'][0]['installment_number'] ?? null;
            $this->notificationService->sendPaymentConfirmationNotification($loan, $amount, $installmentNumber);

            return [
                'success' => true,
                'repayment_id' => $repayment->id,
                'installments_covered' => count($paymentResult['coveredInstallments']),
                'excess_amount' => $paymentResult['excessAmount'],
                'message' => $paymentResult['message']
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment processing failed', [
                'loan_id' => $loan->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate how payment should be allocated across installments
     */
    private function calculatePaymentAllocation($amount, $pendingInstallments, $paymentType)
    {
        $remainingAmount = $amount;
        $coveredInstallments = [];
        $excessAmount = 0;

        foreach ($pendingInstallments as $installment) {
            if ($remainingAmount <= 0) {
                break;
            }

            $remainingDue = $installment->amount_due - $installment->amount_paid;
            $amountToApply = min($remainingAmount, $remainingDue);

            if ($amountToApply > 0) {
                $coveredInstallments[] = [
                    'id' => $installment->id,
                    'installment_number' => $installment->installment_number,
                    'amount_due' => $installment->amount_due,
                    'amount_previously_paid' => $installment->amount_paid,
                    'amount_applied' => $amountToApply,
                    'remaining_after_payment' => $remainingDue - $amountToApply
                ];

                $remainingAmount -= $amountToApply;
            }
        }

        $excessAmount = max(0, $remainingAmount);

        // Validate payment based on type
        switch ($paymentType) {
            case 'regular':
                // Regular payment should only cover current due installment
                if (count($coveredInstallments) > 1 || $excessAmount > 0) {
                    return [
                        'canProcess' => false,
                        'message' => 'Regular payment can only cover the current due installment. Use advance or bulk payment for multiple installments.'
                    ];
                }
                break;

            case 'advance':
                // Advance payment can cover current installment but paid early
                if (count($coveredInstallments) > 1) {
                    return [
                        'canProcess' => false,
                        'message' => 'Advance payment can only cover one installment. Use bulk payment for multiple installments.'
                    ];
                }
                break;

            case 'bulk':
                // Bulk payment can cover multiple installments
                // No additional restrictions
                break;
        }

        return [
            'canProcess' => true,
            'coveredInstallments' => $coveredInstallments,
            'excessAmount' => $excessAmount,
            'message' => sprintf(
                'Payment processed successfully. Covered %d installment(s). %s',
                count($coveredInstallments),
                $excessAmount > 0 ? sprintf('Excess amount: ETB %s will be credited to future payments.', number_format($excessAmount, 2)) : ''
            )
        ];
    }

    /**
     * Update loan's next due date
     */
    private function updateLoanNextDueDate(Loan $loan)
    {
        $nextInstallment = $loan->schedules()
            ->whereIn('status', ['Pending', 'Partial'])
            ->orderBy('installment_number')
            ->first();

        if ($nextInstallment) {
            $loan->next_due_date = $nextInstallment->due_date;
        } else {
            $loan->next_due_date = null; // All installments paid
        }

        $loan->save();
    }

    /**
     * Get payment summary for a loan
     */
    public function getPaymentSummary(Loan $loan)
    {
        $schedules = $loan->schedules()->orderBy('installment_number')->get();
        $repayments = $loan->repayments()->orderBy('payment_date')->get();

        $totalDue = $schedules->sum('amount_due');
        $totalPaid = $repayments->sum('installment_amount');
        $remainingBalance = $totalDue - $totalPaid;

        $nextInstallment = $schedules->whereIn('status', ['Pending', 'Partial'])->first();
        
        return [
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'remaining_balance' => $remainingBalance,
            'next_due_date' => $nextInstallment ? $nextInstallment->due_date : null,
            'next_due_amount' => $nextInstallment ? ($nextInstallment->amount_due - $nextInstallment->amount_paid) : 0,
            'total_installments' => $schedules->count(),
            'paid_installments' => $schedules->where('status', 'Paid')->count(),
            'pending_installments' => $schedules->whereIn('status', ['Pending', 'Partial'])->count(),
            'payment_history' => $repayments->map(function ($repayment) {
                return [
                    'id' => $repayment->id,
                    'amount' => $repayment->installment_amount,
                    'payment_date' => $repayment->payment_date->format('M d, Y'),
                    'payment_type' => $repayment->payment_type,
                    'payment_method' => $repayment->payment_method,
                    'installments_covered' => json_decode($repayment->installments_covered) ?? [],
                    'excess_amount' => $repayment->excess_amount,
                    'note' => $repayment->payment_note,
                ];
            })
        ];
    }

    public function calculateEarlyPaymentDiscount(Loan $loan, $installmentNumber, $daysEarly)
    {
        // Example: 5% discount if paid 30+ days early, 2% if 15+ days early
        if ($daysEarly >= 30) {
            return 0.05; // 5% discount
        } elseif ($daysEarly >= 15) {
            return 0.02; // 2% discount
        }
        
        return 0; // No discount
    }

    /**
     * Get available payment options for a loan
     */
    public function getPaymentOptions(Loan $loan)
    {
        $nextInstallment = $loan->schedules()
            ->whereIn('status', ['Pending', 'Partial'])
            ->orderBy('installment_number')
            ->first();

        if (!$nextInstallment) {
            return [
                'can_pay' => false,
                'message' => 'All installments have been paid.'
            ];
        }

        $daysUntilDue = now()->diffInDays($nextInstallment->due_date, false);
        $remainingDue = $nextInstallment->amount_due - $nextInstallment->amount_paid;

        return [
            'can_pay' => true,
            'next_installment' => [
                'number' => $nextInstallment->installment_number,
                'due_date' => $nextInstallment->due_date->format('M d, Y'),
                'days_until_due' => $daysUntilDue,
                'amount_due' => $remainingDue,
                'is_early' => $daysUntilDue > 0
            ],
            'payment_options' => [
                'regular' => [
                    'available' => true,
                    'max_amount' => $remainingDue,
                    'description' => 'Pay current installment only'
                ],
                'advance' => [
                    'available' => $daysUntilDue > 0,
                    'max_amount' => $remainingDue,
                    'description' => 'Pay current installment early',
                    'discount' => $daysUntilDue > 0 ? $this->calculateEarlyPaymentDiscount($loan, $nextInstallment->installment_number, $daysUntilDue) : 0
                ],
                'bulk' => [
                    'available' => $loan->schedules()->whereIn('status', ['Pending', 'Partial'])->count() > 1,
                    'max_amount' => $loan->schedules()->whereIn('status', ['Pending', 'Partial'])->sum('amount_due') - $loan->schedules()->whereIn('status', ['Pending', 'Partial'])->sum('amount_paid'),
                    'description' => 'Pay multiple installments at once'
                ]
            ]
        ];
    }

    private function logAudit($action, $model, $data = [])
    {
        if (class_exists('\App\Models\AuditLog')) {
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'action' => $action,
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'data' => json_encode($data),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
    }
}
