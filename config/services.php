<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sms' => [
        'api_key' => env('SMS_API_KEY'),
        'api_url' => env('SMS_API_URL', 'https://api.sms-provider.com/send'),
        'sender_id' => env('SMS_SENDER_ID', 'EndekiseMF'),
    ],

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'email_from' => env('BREVO_EMAIL_FROM', 'noreply@endekise.local'),
        'email_from_name' => env('BREVO_EMAIL_FROM_NAME', 'Endekise Microfinance'),
    ],

];
