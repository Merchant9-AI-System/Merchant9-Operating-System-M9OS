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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Used gold balance daripada kedaiemas (App\Support\UsedGoldBalanceProvider). Token = nilai yang sama
    // dengan INTERNAL_BO_SHARED_SECRET di .env kedaiemas.
    'kedaiemas' => [
        'used_gold_balance_url' => env('KEDAIEMAS_USED_GOLD_BALANCE_URL', 'https://uat.kedaiemas.my/api/used-gold-balance'),
        'internal_token' => env('KEDAIEMAS_INTERNAL_TOKEN'),
    ],

];
