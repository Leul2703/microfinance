<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanPaymentSchedule;
use App\Models\SmsLog;
use App\Services\BrevoEmailService;
use App\Services\SmsGatewayService;

class NotificationService
{
    private $emailService;
    private $smsService;

    public function __construct(BrevoEmailService $emailService, SmsGatewayService $smsService)
    {
        $this->emailService = $emailService;
        $this->smsService = $smsService;
    }

    /**
     * Send loan approval notification
     */
    public function sendLoanApprovalNotification(Loan $loan)
    {
        $customer = $loan->customer;
        $subject = 'Your Loan Application Has Been Approved!';
        $message = sprintf(
            "Dear %s,<br><br>Your loan application (ID: %d) for ETB %s has been APPROVED.<br><br>Please visit your branch for further processing.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $loan->id,
            number_format($loan->requested_amount, 2)
        );

        return $this->sendToCustomer($customer, $message, $loan->id, $subject);
    }

    /**
     * Send loan rejection notification
     */
    public function sendLoanRejectionNotification(Loan $loan)
    {
        $customer = $loan->customer;
        $subject = 'Update on Your Loan Application';
        $reason = $loan->rejection_reason ?? 'Contact your branch for details';
        
        $message = sprintf(
            "Dear %s,<br><br>Your loan application (ID: %d) for ETB %s could not be approved at this time.<br><br><strong>Reason:</strong> %s<br><br>Please contact your branch for more information.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $loan->id,
            number_format($loan->requested_amount, 2),
            $reason
        );

        return $this->sendToCustomer($customer, $message, $loan->id, $subject);
    }

    /**
     * Send payment reminder notification
     */
    public function sendPaymentReminder(LoanPaymentSchedule $schedule)
    {
        $loan = $schedule->loan;
        $customer = $loan->customer;
        $subject = 'Payment Reminder - Loan Installment Due';
        
        $message = sprintf(
            "Dear %s,<br><br>This is a friendly reminder that your loan installment is due soon.<br><br><strong>Loan Details:</strong><br>Loan ID: %d<br>Installment Number: %d<br>Amount Due: ETB %s<br>Due Date: %s<br><br>Please ensure timely payment to avoid any late fees.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $loan->id,
            $schedule->installment_number,
            number_format($schedule->amount_due, 2),
            $schedule->due_date->format('M d, Y')
        );

        return $this->sendToCustomer($customer, $message, $loan->id, $subject);
    }

    /**
     * Send overdue payment notification
     */
    public function sendOverduePaymentNotification(LoanPaymentSchedule $schedule)
    {
        $loan = $schedule->loan;
        $customer = $loan->customer;
        $subject = 'URGENT: Loan Payment Overdue';
        
        $message = sprintf(
            "Dear %s,<br><br><strong>URGENT NOTICE:</strong> Your loan installment is now OVERDUE.<br><br><strong>Overdue Payment Details:</strong><br>Loan ID: %d<br>Installment Number: %d<br>Amount Due: ETB %s<br>Original Due Date: %s<br><br>Please make immediate payment to avoid additional late fees and potential impact on your credit standing.<br><br>If you have already made this payment, please disregard this notice.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $loan->id,
            $schedule->installment_number,
            number_format($schedule->amount_due, 2),
            $schedule->due_date->format('M d, Y')
        );

        return $this->sendToCustomer($customer, $message, $loan->id, $subject);
    }

    /**
     * Send payment confirmation notification
     */
    public function sendPaymentConfirmationNotification(Loan $loan, $amount, $installmentNumber = null)
    {
        $customer = $loan->customer;
        $installmentText = $installmentNumber ? " (Installment {$installmentNumber})" : '';
        $subject = 'Payment Confirmation - Thank You!';
        
        $message = sprintf(
            "Dear %s,<br><br>Thank you for your payment!<br><br><strong>Payment Details:</strong><br>Loan ID: %d%s<br>Amount Paid: ETB %s<br>Payment Date: %s<br><br>Your payment has been successfully processed and applied to your loan account.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $loan->id,
            $installmentText,
            number_format($amount, 2),
            now()->format('M d, Y')
        );

        return $this->sendToCustomer($customer, $message, $loan->id, $subject);
    }

    /**
     * Send loan disbursement notification
     */
    public function sendLoanDisbursementNotification(Loan $loan)
    {
        $customer = $loan->customer;
        $subject = 'Good News! Your Loan Has Been Disbursed';
        
        $message = sprintf(
            "Dear %s,<br><br>Good news! Your loan has been successfully disbursed.<br><br><strong>Loan Disbursement Details:</strong><br>Loan ID: %d<br>Loan Amount: ETB %s<br>Disbursement Date: %s<br>First Payment Due: %s<br><br>The funds should now be available in your account. Please make payments according to the schedule to maintain good standing.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $loan->id,
            number_format($loan->requested_amount, 2),
            now()->format('M d, Y'),
            $loan->next_due_date ? $loan->next_due_date->format('M d, Y') : 'Contact your branch'
        );

        return $this->sendToCustomer($customer, $message, $loan->id, $subject);
    }

    /**
     * Send savings account approval notification
     */
    public function sendSavingsAccountApprovalNotification($account)
    {
        $customer = $account->customer;
        $subject = 'Your Savings Account Has Been Approved';
        
        $message = sprintf(
            "Dear %s,<br><br>Congratulations! Your savings account has been approved and is now active.<br><br><strong>Account Details:</strong><br>Account Number: %s<br>Account Type: %s<br>Approval Date: %s<br><br>You can now start depositing funds to your account. Thank you for choosing Endekise Microfinance for your savings needs.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $account->account_number,
            $account->savings_type ?? 'Savings Account',
            now()->format('M d, Y')
        );

        return $this->sendToCustomer($customer, $message, null, $subject);
    }

    /**
     * Send savings deposit confirmation
     */
    public function sendSavingsDepositConfirmation($account, $amount)
    {
        $customer = $account->customer;
        $subject = 'Deposit Confirmation';
        
        $message = sprintf(
            "Dear %s,<br><br>We have received your deposit to your savings account.<br><br><strong>Deposit Details:</strong><br>Account Number: %s<br>Deposit Amount: ETB %s<br>Deposit Date: %s<br>New Balance: ETB %s<br><br>Thank you for saving with Endekise Microfinance.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name,
            $account->account_number,
            number_format($amount, 2),
            now()->format('M d, Y'),
            number_format($account->balance, 2)
        );

        return $this->sendToCustomer($customer, $message, null, $subject);
    }

    /**
     * Send welcome message to new customers
     */
    public function sendWelcomeMessage(Customer $customer)
    {
        $subject = 'Welcome to Endekise Microfinance!';
        
        $message = sprintf(
            "Dear %s,<br><br>Welcome to Endekise Microfinance! Thank you for choosing us as your financial partner.<br><br>We are committed to providing you with excellent service and supporting your financial journey. Whether you're looking for loan products, savings accounts, or other financial services, we're here to help.<br><br><strong>What's Next:</strong><br>• Explore our loan and savings products<br>• Visit your nearest branch for personalized assistance<br>• Contact our support team for any questions<br><br>Thank you for trusting Endekise Microfinance with your financial needs.<br><br>Best regards,<br>Endekise Microfinance Team",
            $customer->full_name
        );

        return $this->sendToCustomer($customer, $message, null, $subject);
    }

    /**
     * Send system maintenance notification
     */
    public function sendMaintenanceNotification(Customer $customer, $scheduledTime)
    {
        $message = sprintf(
            "System Maintenance Notice: Our services will be temporarily unavailable on %s for scheduled maintenance. We apologize for any inconvenience.",
            $scheduledTime
        );

        return $this->sendToCustomer($customer, $message, null, 'System Maintenance');
    }

    /**
     * Send notification to customer (SMS first, email fallback)
     */
    private function sendToCustomer(Customer $customer, $message, $loanId = null, $subject = null)
    {
        $results = [];
        
        // Try SMS first if phone number is available
        if ($customer->phone_number) {
            $smsResult = $this->smsService->sendNotification(
                $customer->phone_number,
                strip_tags($message), // Remove HTML for SMS
                $loanId,
                $customer->id
            );
            $results['sms'] = $smsResult;
        }
        
        // Send to email as backup or if phone not available
        if ($customer->email_address) {
            $emailResult = $this->emailService->sendNotification(
                $customer->email_address,
                $message,
                $loanId,
                $customer->id,
                $subject
            );
            $results['email'] = $emailResult;
        }

        return $results;
    }

    /**
     * Send bulk notifications to multiple customers
     */
    public function sendBulkNotification($customers, $message, $subject = null)
    {
        $results = [];
        
        foreach ($customers as $customer) {
            $result = $this->sendToCustomer($customer, $message, null, $subject);
            $results[] = [
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'result' => $result
            ];
        }

        return $results;
    }

    /**
     * Send custom notification
     */
    public function sendCustomNotification(Customer $customer, $message, $subject = null)
    {
        return $this->sendToCustomer($customer, $message, null, $subject);
    }

    /**
     * Check Brevo email service status
     */
    public function checkEmailServiceStatus()
    {
        $stats = $this->emailService->getAccountStats();
        
        return [
            'service_available' => $stats !== null,
            'account_stats' => $stats,
            'last_check' => now()
        ];
    }

    /**
     * Check SMS gateway status
     */
    public function checkSmsGatewayStatus()
    {
        // Check both SMS and email services
        $smsStatus = $this->smsService ? $this->smsService->getBalance() : null;
        $emailStatus = $this->emailService ? $this->emailService->getAccountStats() : null;
        
        return [
            'sms_gateway_available' => $smsStatus !== null,
            'sms_balance' => $smsStatus,
            'email_service_available' => $emailStatus !== null,
            'email_stats' => $emailStatus,
            'last_check' => now()
        ];
    }
}
