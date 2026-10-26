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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | PayPal and Stripe are registered in the `payment_methods` table: the
    | gateway mode lives in its non-secret `config` JSON, and client IDs /
    | secrets live in the encrypted `credentials` column (APP_KEY). Nothing
    | gateway-related is read from .env — rotate keys with a DB update.
    |
    | See App\Services\Payment\PaymentCredentials.
    |
    */

    // Charity Navigator (rated US charities for formula autocomplete).
    // Self-serve free keys. When absent, search degrades to ProPublica-only.
    'charity_navigator' => [
        'app_id' => env('CHARITY_NAV_APP_ID'),
        'app_key' => env('CHARITY_NAV_APP_KEY'),
    ],

];
