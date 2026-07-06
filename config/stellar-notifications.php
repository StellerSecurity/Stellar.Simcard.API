<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stellar Notifications API Client
    |--------------------------------------------------------------------------
    |
    | This package is a lightweight client for calling your Stellar Notifications
    | API from other Laravel apps (VPN, Antivirus, Commerce, etc).
    |
    | NOTE: No production URLs are shipped as defaults. Configure your own.
    |
    */

    'base_url' => env('STELLAR_NOTIFICATIONS_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Basic Authentication
    |--------------------------------------------------------------------------
    |
    | The Notifications API is protected with HTTP Basic Auth.
    | Set these in your consuming application's environment.
    |
    */

    'basic_username' => env('STELLAR_NOTIFICATIONS_BASIC_USERNAME', ''),
    'basic_password' => env('STELLAR_NOTIFICATIONS_BASIC_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Defaults are intentionally production-safe. The client also has hardcoded
    | fallbacks, so retry/timeout still work if an app has an old published
    | config file without these keys.
    |
    */

    'timeout' => (int) env('STELLAR_NOTIFICATIONS_TIMEOUT', 30),
    'connect_timeout' => (int) env('STELLAR_NOTIFICATIONS_CONNECT_TIMEOUT', 10),

    'retry' => [
        'times' => (int) env('STELLAR_NOTIFICATIONS_RETRY_TIMES', 5),
        'sleep_ms' => (int) env('STELLAR_NOTIFICATIONS_RETRY_SLEEP_MS', 1000),
        'multiplier' => (int) env('STELLAR_NOTIFICATIONS_RETRY_MULTIPLIER', 2),
        'max_sleep_ms' => (int) env('STELLAR_NOTIFICATIONS_RETRY_MAX_SLEEP_MS', 10000),
    ],
];
