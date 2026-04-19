<?php

namespace App\Console\Commands;

use App\Models\LoanPaymentSchedule;
use App\Models\SmsLog;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'reminders:payments';
    protected $description = 'Send SMS/email reminders for upcoming loan installments';

    private $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    public function handle(): int
    {
        $start = now()->toDateString();
        $end = now()->addDays(7)->toDateString();

        $this->info('Checking for payment reminders from ' . $start . ' to ' . $end);

        // Get upcoming payments
        $schedules = LoanPaymentSchedule::query()
            ->with('loan.customer')
            ->whereIn('status', ['Pending', 'Partial'])
            ->whereBetween('due_date', [$start, $end])
            ->get();

        $overdueSchedules = LoanPaymentSchedule::query()
            ->with('loan.customer')
            ->whereIn('status', ['Pending', 'Partial'])
            ->where('due_date', '<', $start)
            ->get();

        $sentCount = 0;
        $failedCount = 0;

        // Send upcoming payment reminders
        foreach ($schedules as $schedule) {
            $loan = $schedule->loan;
            if (!$loan || !$loan->customer) {
                continue;
            }

            try {
                $result = $this->notificationService->sendPaymentReminder($schedule);
                
                if ($result['sms']['success'] ?? $result['email']['success'] ?? false) {
                    $sentCount++;
                    $this->info("Reminder sent for Loan #{$loan->id}, Installment #{$schedule->installment_number}");
                } else {
                    $failedCount++;
                    $this->error("Failed to send reminder for Loan #{$loan->id}");
                }
            } catch (\Exception $e) {
                $failedCount++;
                $this->error("Error sending reminder for Loan #{$loan->id}: " . $e->getMessage());
            }
        }

        // Send overdue payment notices
        foreach ($overdueSchedules as $schedule) {
            $loan = $schedule->loan;
            if (!$loan || !$loan->customer) {
                continue;
            }

            try {
                $result = $this->notificationService->sendOverduePaymentNotification($schedule);
                
                if ($result['sms']['success'] ?? $result['email']['success'] ?? false) {
                    $sentCount++;
                    $this->info("Overdue notice sent for Loan #{$loan->id}, Installment #{$schedule->installment_number}");
                } else {
                    $failedCount++;
                    $this->error("Failed to send overdue notice for Loan #{$loan->id}");
                }
            } catch (\Exception $e) {
                $failedCount++;
                $this->error("Error sending overdue notice for Loan #{$loan->id}: " . $e->getMessage());
            }
        }

        $totalProcessed = $schedules->count() + $overdueSchedules->count();
        
        $this->info("Payment reminder summary:");
        $this->info("  Total schedules processed: {$totalProcessed}");
        $this->info("  Reminders sent successfully: {$sentCount}");
        $this->info("  Failed: {$failedCount}");

        // Check email service status
        $status = $this->notificationService->checkEmailServiceStatus();
        if ($status['email_service_available']) {
            $this->info("Email service is operational");
        } else {
            $this->warn("Email service is not responding");
        }

        return Command::SUCCESS;
    }
}
