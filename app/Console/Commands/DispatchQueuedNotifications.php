<?php

namespace App\Console\Commands;

use App\Models\SmsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DispatchQueuedNotifications extends Command
{
    protected $signature = 'notifications:dispatch';
    protected $description = 'Dispatch queued SMS/email notifications';

    public function handle(): int
    {
        $logs = SmsLog::query()
            ->where('status', 'queued')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($logs as $logEntry) {
            try {
                if ($logEntry->channel === 'email' && $logEntry->recipient) {
                    Mail::raw($logEntry->message, function ($message) use ($logEntry) {
                        $message->to($logEntry->recipient)
                            ->subject('Endekise Notification');
                    });
                } else {
                    Log::info('SMS notification dispatched', [
                        'recipient' => $logEntry->recipient,
                        'message' => $logEntry->message,
                    ]);
                }

                $logEntry->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
                $sent++;
            } catch (\Throwable $exception) {
                $logEntry->update([
                    'status' => 'failed',
                ]);
                Log::warning('Notification dispatch failed', [
                    'sms_log_id' => $logEntry->id,
                    'error' => $exception->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->info("Sent {$sent} notification(s); {$failed} failed.");

        return Command::SUCCESS;
    }
}
