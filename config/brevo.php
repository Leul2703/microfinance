<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Brevo Email Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Brevo (formerly Sendinblue) email service.
    | Update these values with your Brevo API credentials.
    |
    */

    'api_key' => env('BREVO_API_KEY', 'your_brevo_api_key_here'),
    'api_url' => env('BREVO_API_URL', 'https://api.brevo.com/v3'),

    /*
    |--------------------------------------------------------------------------
    | Email Settings
    |--------------------------------------------------------------------------
    |
    | General email settings and preferences.
    |
    */

    'enabled' => env('BREVO_ENABLED', true),
    'sender_email' => env('BREVO_SENDER_EMAIL', 'noreply@endekise.com'),
    'sender_name' => env('BREVO_SENDER_NAME', 'Endekise Microfinance'),
    'reply_to' => env('BREVO_REPLY_TO', 'support@endekise.com'),

    /*
    |--------------------------------------------------------------------------
    | Email Templates
    |--------------------------------------------------------------------------
    |
    | Pre-defined email templates for different notification types.
    |
    */

    'templates' => [
        'loan_approved' => [
            'name' => 'Loan Approval Notification',
            'subject' => 'Your Loan Application Has Been Approved!',
            'template' => 'loan_approved'
        ],
        'loan_rejected' => [
            'name' => 'Loan Rejection Notification', 
            'subject' => 'Update on Your Loan Application',
            'template' => 'loan_rejected'
        ],
        'payment_reminder' => [
            'name' => 'Payment Reminder',
            'subject' => 'Payment Reminder - Loan Installment Due',
            'template' => 'payment_reminder'
        ],
        'payment_overdue' => [
            'name' => 'Overdue Payment Notice',
            'subject' => 'URGENT: Loan Payment Overdue',
            'template' => 'payment_overdue'
        ],
        'payment_confirmation' => [
            'name' => 'Payment Confirmation',
            'subject' => 'Payment Confirmation - Thank You!',
            'template' => 'payment_confirmation'
        ],
        'loan_disbursement' => [
            'name' => 'Loan Disbursement Notification',
            'subject' => 'Good News! Your Loan Has Been Disbursed',
            'template' => 'loan_disbursement'
        ],
        'welcome' => [
            'name' => 'Welcome Email',
            'subject' => 'Welcome to Endekise Microfinance!',
            'template' => 'welcome'
        ],
        'savings_approval' => [
            'name' => 'Savings Account Approval',
            'subject' => 'Your Savings Account Has Been Approved',
            'template' => 'savings_approval'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent spam by limiting emails per recipient.
    |
    */

    'rate_limiting' => [
        'enabled' => env('BREVO_RATE_LIMITING', true),
        'max_emails_per_hour' => env('BREVO_MAX_PER_HOUR', 20),
        'max_emails_per_day' => env('BREVO_MAX_PER_DAY', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking and Analytics
    |--------------------------------------------------------------------------
    |
    | Configure email tracking and analytics.
    |
    */

    'tracking' => [
        'enabled' => env('BREVO_TRACKING_ENABLED', true),
        'click_tracking' => env('BREVO_CLICK_TRACKING', true),
        'open_tracking' => env('BREVO_OPEN_TRACKING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for email operations.
    |
    */

    'logging' => [
        'enabled' => env('BREVO_LOGGING', true),
        'log_level' => env('BREVO_LOG_LEVEL', 'info'),
        'log_failed_only' => env('BREVO_LOG_FAILED_ONLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for fallback to Laravel Mail when Brevo fails.
    |
    */

    'fallback' => [
        'enabled' => env('BREVO_FALLBACK_ENABLED', true),
        'max_retries' => env('BREVO_MAX_RETRIES', 3),
        'retry_delay' => env('BREVO_RETRY_DELAY', 5), // seconds
    ],
];
