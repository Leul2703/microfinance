<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for SMS gateway service. Update these values with your
    | SMS provider's credentials.
    |
    */

    'api_key' => env('SMS_API_KEY', 'your_api_key_here'),
    'api_url' => env('SMS_API_URL', 'https://api.sms-provider.com/send'),
    'sender_id' => env('SMS_SENDER_ID', 'EndekiseMF'),

    /*
    |--------------------------------------------------------------------------
    | SMS Settings
    |--------------------------------------------------------------------------
    |
    | General SMS settings and preferences.
    |
    */

    'enabled' => env('SMS_ENABLED', true),
    'max_retries' => env('SMS_MAX_RETRIES', 3),
    'retry_delay' => env('SMS_RETRY_DELAY', 60), // seconds

    /*
    |--------------------------------------------------------------------------
    | Email Fallback Settings
    |--------------------------------------------------------------------------
    |
    | Email settings to use when SMS fails.
    |
    */

    'email_fallback' => env('SMS_EMAIL_FALLBACK', true),
    'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@endekise.com'),
    'from_name' => env('MAIL_FROM_NAME', 'Endekise Microfinance'),

    /*
    |--------------------------------------------------------------------------
    | Notification Templates
    |--------------------------------------------------------------------------
    |
    | Pre-defined message templates for different notification types.
    |
    */

    'templates' => [
        'loan_approved' => 'Dear {customer_name}, Your loan application (ID: {loan_id}) for ETB {amount} has been APPROVED. Please visit your branch for further processing.',
        'loan_rejected' => 'Dear {customer_name}, Your loan application (ID: {loan_id}) for ETB {amount} could not be approved. Reason: {reason}',
        'payment_reminder' => 'Reminder: Your loan installment (ID: {loan_id}, Installment: {installment}) of ETB {amount} is due on {due_date}. Please pay on time.',
        'payment_overdue' => 'URGENT: Your loan installment (ID: {loan_id}, Installment: {installment}) of ETB {amount} was due on {due_date} and is now OVERDUE.',
        'payment_confirmation' => 'Thank you! Your payment of ETB {amount} for loan ID {loan_id} has been received.',
        'loan_disbursement' => 'Good news! Your loan (ID: {loan_id}) of ETB {amount} has been disbursed. First payment due: {due_date}.',
        'welcome' => 'Welcome to Endekise Microfinance, {customer_name}! Thank you for choosing us.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent spam by limiting messages per recipient.
    |
    */

    'rate_limiting' => [
        'enabled' => env('SMS_RATE_LIMITING', true),
        'max_messages_per_hour' => env('SMS_MAX_PER_HOUR', 10),
        'max_messages_per_day' => env('SMS_MAX_PER_DAY', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for SMS operations.
    |
    */

    'logging' => [
        'enabled' => env('SMS_LOGGING', true),
        'log_level' => env('SMS_LOG_LEVEL', 'info'),
        'log_failed_only' => env('SMS_LOG_FAILED_ONLY', false),
    ],
];
