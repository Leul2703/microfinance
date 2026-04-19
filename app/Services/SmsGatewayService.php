<?php

namespace App\Services;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsGatewayService
{
    private $apiKey;
    private $apiUrl;
    private $senderId;

    public function __construct()
    {
        $this->apiKey = config('services.sms.api_key');
        $this->apiUrl = config('services.sms.api_url');
        $this->senderId = config('services.sms.sender_id', 'EndekiseMF');
    }

    /**
     * Send SMS message
     */
    public function sendSms($phoneNumber, $message, $loanId = null, $customerId = null)
    {
        try {
            // Validate phone number format
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);
            
            if (!$phoneNumber) {
                throw new \InvalidArgumentException('Invalid phone number format');
            }

            // Create SMS log entry
            $smsLog = SmsLog::create([
                'loan_id' => $loanId,
                'customer_id' => $customerId,
                'channel' => 'sms',
                'recipient' => $phoneNumber,
                'message' => $message,
                'status' => 'sending',
            ]);

            // Send SMS via gateway
            $response = $this->sendViaGateway($phoneNumber, $message);

            if ($response['success']) {
                $smsLog->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
                
                Log::info('SMS sent successfully', [
                    'sms_id' => $smsLog->id,
                    'recipient' => $phoneNumber,
                    'message' => substr($message, 0, 50) . '...',
                ]);

                return true;
            } else {
                $smsLog->update([
                    'status' => 'failed',
                ]);

                Log::error('SMS sending failed', [
                    'sms_id' => $smsLog->id,
                    'recipient' => $phoneNumber,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);

                return false;
            }
        } catch (\Exception $e) {
            Log::error('SMS service error', [
                'error' => $e->getMessage(),
                'recipient' => $phoneNumber ?? 'unknown',
            ]);

            if (isset($smsLog)) {
                $smsLog->update(['status' => 'failed']);
            }

            return false;
        }
    }

    /**
     * Send email notification (fallback for SMS)
     */
    public function sendEmail($emailAddress, $subject, $message, $loanId = null, $customerId = null)
    {
        try {
            // Create SMS log entry for email
            $smsLog = SmsLog::create([
                'loan_id' => $loanId,
                'customer_id' => $customerId,
                'channel' => 'email',
                'recipient' => $emailAddress,
                'message' => $message,
                'status' => 'sending',
            ]);

            // Send email
            $sent = \Illuminate\Support\Facades\Mail::raw($message, function ($message) use ($emailAddress, $subject) {
                $message->to($emailAddress)
                       ->subject($subject);
            });

            if ($sent) {
                $smsLog->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                Log::info('Email sent successfully', [
                    'sms_id' => $smsLog->id,
                    'recipient' => $emailAddress,
                ]);

                return true;
            } else {
                $smsLog->update(['status' => 'failed']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Email service error', [
                'error' => $e->getMessage(),
                'recipient' => $emailAddress ?? 'unknown',
            ]);

            if (isset($smsLog)) {
                $smsLog->update(['status' => 'failed']);
            }

            return false;
        }
    }

    /**
     * Send notification (SMS first, email fallback)
     */
    public function sendNotification($recipient, $message, $loanId = null, $customerId = null, $subject = null)
    {
        // Try SMS first if phone number is available
        if ($this->isPhoneNumber($recipient)) {
            $smsSent = $this->sendSms($recipient, $message, $loanId, $customerId);
            if ($smsSent) {
                return ['success' => true, 'channel' => 'sms'];
            }
        }

        // Fallback to email
        if ($this->isEmail($recipient)) {
            $emailSubject = $subject ?? 'Notification from Endekise Microfinance';
            $emailSent = $this->sendEmail($recipient, $emailSubject, $message, $loanId, $customerId);
            if ($emailSent) {
                return ['success' => true, 'channel' => 'email'];
            }
        }

        return ['success' => false, 'error' => 'Unable to send notification'];
    }

    /**
     * Send SMS via external gateway
     */
    private function sendViaGateway($phoneNumber, $message)
    {
        // Example implementation - adjust based on your SMS provider
        try {
            $response = Http::timeout(30)->post($this->apiUrl, [
                'api_key' => $this->apiKey,
                'to' => $phoneNumber,
                'message' => $message,
                'sender' => $this->senderId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Handle different gateway response formats
                if (isset($data['status']) && $data['status'] === 'success') {
                    return ['success' => true, 'message_id' => $data['message_id'] ?? null];
                } elseif (isset($data['success']) && $data['success']) {
                    return ['success' => true, 'message_id' => $data['message_id'] ?? null];
                }
            }

            return ['success' => false, 'error' => 'Gateway error: ' . $response->body()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Connection error: ' . $e->getMessage()];
        }
    }

    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber($phoneNumber)
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Remove leading zeros
        $phone = ltrim($phone, '0');
        
        // Add Ethiopia country code if not present
        if (strlen($phone) === 9 && substr($phone, 0, 1) === '9') {
            return '251' . $phone;
        }
        
        // If already has country code
        if (strlen($phone) === 12 && substr($phone, 0, 3) === '251') {
            return $phone;
        }
        
        return null;
    }

    /**
     * Check if string is a phone number
     */
    private function isPhoneNumber($string)
    {
        return preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $string);
    }

    /**
     * Check if string is an email
     */
    private function isEmail($string)
    {
        return filter_var($string, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Get delivery status for a message
     */
    public function getDeliveryStatus($messageId)
    {
        try {
            $response = Http::timeout(10)->get($this->apiUrl . '/status', [
                'api_key' => $this->apiKey,
                'message_id' => $messageId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('SMS status check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get account balance
     */
    public function getBalance()
    {
        try {
            $response = Http::timeout(10)->get($this->apiUrl . '/balance', [
                'api_key' => $this->apiKey,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('SMS balance check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
