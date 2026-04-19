<?php

namespace App\Services;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BrevoEmailService
{
    private $apiKey;
    private $apiUrl;
    private $senderEmail;
    private $senderName;

    public function __construct()
    {
        $this->apiKey = config('services.brevo.api_key');
        $this->apiUrl = config('services.brevo.api_url', 'https://api.brevo.com/v3');
        $this->senderEmail = config('services.brevo.sender_email', 'noreply@endekise.com');
        $this->senderName = config('services.brevo.sender_name', 'Endekise Microfinance');
    }

    /**
     * Send email notification
     */
    public function sendEmail($toEmail, $subject, $message, $loanId = null, $customerId = null)
    {
        try {
            // Create email log entry
            $emailLog = SmsLog::create([
                'loan_id' => $loanId,
                'customer_id' => $customerId,
                'channel' => 'email',
                'recipient' => $toEmail,
                'message' => $message,
                'status' => 'sending',
            ]);

            // Send email via Brevo API
            $response = $this->sendViaBrevo($toEmail, $subject, $message);

            if ($response['success']) {
                $emailLog->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
                
                Log::info('Email sent successfully via Brevo', [
                    'email_id' => $emailLog->id,
                    'recipient' => $toEmail,
                    'subject' => substr($subject, 0, 50) . '...',
                ]);

                return true;
            } else {
                $emailLog->update([
                    'status' => 'failed',
                ]);

                Log::error('Brevo email sending failed', [
                    'email_id' => $emailLog->id,
                    'recipient' => $toEmail,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);

                // Fallback to Laravel Mail
                return $this->sendViaLaravelMail($toEmail, $subject, $message, $emailLog);
            }
        } catch (\Exception $e) {
            Log::error('Brevo email service error', [
                'error' => $e->getMessage(),
                'recipient' => $toEmail ?? 'unknown',
            ]);

            if (isset($emailLog)) {
                $emailLog->update(['status' => 'failed']);
            }

            return false;
        }
    }

    /**
     * Send email via Brevo API
     */
    private function sendViaBrevo($toEmail, $subject, $message)
    {
        try {
            $response = Http::timeout(30)->post($this->apiUrl . '/smtp/email', [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $this->apiKey,
                    'content-type' => 'application/json',
                ],
                'sender' => [
                    'name' => $this->senderName,
                    'email' => $this->senderEmail,
                ],
                'to' => [
                    ['email' => $toEmail]
                ],
                'subject' => $subject,
                'htmlContent' => $this->formatHtmlEmail($message, $subject),
                'textContent' => strip_tags($message),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Brevo returns messageId in the response
                if (isset($data['messageId'])) {
                    return ['success' => true, 'message_id' => $data['messageId']];
                }
            }

            return ['success' => false, 'error' => 'Brevo API error: ' . $response->body()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Connection error: ' . $e->getMessage()];
        }
    }

    /**
     * Fallback to Laravel Mail
     */
    private function sendViaLaravelMail($toEmail, $subject, $message, $emailLog)
    {
        try {
            Mail::raw($message, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)
                       ->subject($subject)
                       ->from($this->senderEmail, $this->senderName);
            });

            $emailLog->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info('Email sent successfully via Laravel Mail fallback', [
                'email_id' => $emailLog->id,
                'recipient' => $toEmail,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Laravel Mail fallback failed', [
                'error' => $e->getMessage(),
                'recipient' => $toEmail,
            ]);

            $emailLog->update(['status' => 'failed']);
            return false;
        }
    }

    /**
     * Send notification (email only)
     */
    public function sendNotification($recipient, $message, $loanId = null, $customerId = null, $subject = null)
    {
        // Only process email addresses
        if (!$this->isEmail($recipient)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }

        $emailSubject = $subject ?? 'Notification from Endekise Microfinance';
        $sent = $this->sendEmail($recipient, $emailSubject, $message, $loanId, $customerId);
        
        if ($sent) {
            return ['success' => true, 'channel' => 'email'];
        } else {
            return ['success' => false, 'error' => 'Failed to send email'];
        }
    }

    /**
     * Send HTML email template
     */
    public function sendHtmlEmail($toEmail, $subject, $htmlContent, $loanId = null, $customerId = null)
    {
        try {
            $emailLog = SmsLog::create([
                'loan_id' => $loanId,
                'customer_id' => $customerId,
                'channel' => 'email',
                'recipient' => $toEmail,
                'message' => strip_tags($htmlContent),
                'status' => 'sending',
            ]);

            $response = Http::timeout(30)->post($this->apiUrl . '/smtp/email', [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $this->apiKey,
                    'content-type' => 'application/json',
                ],
                'sender' => [
                    'name' => $this->senderName,
                    'email' => $this->senderEmail,
                ],
                'to' => [
                    ['email' => $toEmail]
                ],
                'subject' => $subject,
                'htmlContent' => $htmlContent,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['messageId'])) {
                    $emailLog->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                    ]);

                    return ['success' => true, 'message_id' => $data['messageId']];
                }
            }

            $emailLog->update(['status' => 'failed']);
            return ['success' => false, 'error' => 'Brevo API error: ' . $response->body()];
        } catch (\Exception $e) {
            Log::error('Brevo HTML email error', ['error' => $e->getMessage()]);
            $emailLog->update(['status' => 'failed']);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Format email as HTML
     */
    private function formatHtmlEmail($message, $subject)
    {
        $logoUrl = asset('images/logo.png');
        $companyName = 'Endekise Microfinance';
        $currentYear = date('Y');

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1'>
            <title>{$subject}</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #007bff; }
                .logo { max-width: 150px; }
                .content { padding: 30px 0; }
                .footer { text-align: center; padding: 20px 0; border-top: 1px solid #eee; font-size: 12px; color: #666; }
                .btn { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; }
                .alert { padding: 15px; border: 1px solid transparent; border-radius: 4px; margin: 20px 0; }
                .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }
                .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
                .alert-warning { color: #856404; background-color: #fff3cd; border-color: #ffeaa7; }
                .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <img src='{$logoUrl}' alt='{$companyName}' class='logo'>
                </div>
                <div class='content'>
                    {$message}
                </div>
                <div class='footer'>
                    <p>&copy; {$currentYear} {$companyName}. All rights reserved.</p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>If you have questions, please contact our support team.</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Check if string is an email
     */
    private function isEmail($string)
    {
        return filter_var($string, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Get Brevo account statistics
     */
    public function getAccountStats()
    {
        try {
            $response = Http::timeout(10)->get($this->apiUrl . '/account', [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $this->apiKey,
                ]
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Brevo account stats check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get email delivery status
     */
    public function getEmailStatus($messageId)
    {
        try {
            $response = Http::timeout(10)->get($this->apiUrl . '/smtp/status/' . $messageId, [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $this->apiKey,
                ]
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Brevo email status check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Create email template in Brevo
     */
    public function createTemplate($name, $htmlContent, $subject = '')
    {
        try {
            $response = Http::timeout(30)->post($this->apiUrl . '/templates', [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $this->apiKey,
                    'content-type' => 'application/json',
                ],
                'name' => $name,
                'subject' => $subject,
                'htmlContent' => $htmlContent,
                'isActive' => true,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return ['success' => true, 'template_id' => $data['id'] ?? null];
            }

            return ['success' => false, 'error' => 'Failed to create template: ' . $response->body()];
        } catch (\Exception $e) {
            Log::error('Brevo template creation failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send transactional email template
     */
    public function sendTemplateEmail($toEmail, $templateId, $params = [], $loanId = null, $customerId = null)
    {
        try {
            $emailLog = SmsLog::create([
                'loan_id' => $loanId,
                'customer_id' => $customerId,
                'channel' => 'email',
                'recipient' => $toEmail,
                'message' => "Template: {$templateId}",
                'status' => 'sending',
            ]);

            $response = Http::timeout(30)->post($this->apiUrl . '/smtp/email', [
                'headers' => [
                    'accept' => 'application/json',
                    'api-key' => $this->apiKey,
                    'content-type' => 'application/json',
                ],
                'sender' => [
                    'name' => $this->senderName,
                    'email' => $this->senderEmail,
                ],
                'to' => [
                    ['email' => $toEmail]
                ],
                'templateId' => $templateId,
                'params' => $params,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['messageId'])) {
                    $emailLog->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                    ]);

                    return ['success' => true, 'message_id' => $data['messageId']];
                }
            }

            $emailLog->update(['status' => 'failed']);
            return ['success' => false, 'error' => 'Brevo API error: ' . $response->body()];
        } catch (\Exception $e) {
            Log::error('Brevo template email error', ['error' => $e->getMessage()]);
            $emailLog->update(['status' => 'failed']);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
