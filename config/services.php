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

    'esimaccess' => [
        'base_url' => env('ESIMACCESS_BASE_URL'),
        'topup_path' => env('ESIMACCESS_TOPUP_PATH', '/v1/open/esim/topup'),
        'webhook_secret' => env('ESIMACCESS_WEBHOOK_SECRET'),
        'accounts' => [
            'primary' => [
                'access_code' => env('ESIMACCESS_ACCESS_CODE'),
                'secret_key' => env('ESIMACCESS_SECRET_KEY'),
            ],
            'legacy' => [
                'access_code' => env('ESIMACCESS_LEGACY_ACCESS_CODE'),
                'secret_key' => env('ESIMACCESS_LEGACY_SECRET_KEY'),
            ],
        ],
    ],

    'stellar_data' => [
        'topup_url' => env('STELLAR_DATA_TOPUP_URL', 'https://data.stellarsecurity.com/topup'),
        'topup_checkout_url' => env('STELLAR_DATA_TOPUP_CHECKOUT_URL'),
    ],

    'stellar_commerce' => [
        'topup_checkout_url' => env('STELLAR_COMMERCE_TOPUP_CHECKOUT_URL'),
        'username' => env('APPSETTING_API_USERNAME_STELLAR_COMMERCE_API'),
        'password' => env('APPSETTING_API_PASSWORD_STELLAR_COMMERCE_API'),
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

];
