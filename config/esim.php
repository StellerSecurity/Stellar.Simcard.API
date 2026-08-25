<?php

return [

    'crypto' => [
        // Used to compute HMAC(plan_id) for DB lookup.
        'hash_key' => env('ESIM_PLAN_HASH_KEY', ''),

        // Used as master key to derive per-plan data encryption keys.
        'master_key' => env('ESIM_PLAN_MASTER_KEY', ''),
    ],

    'user_reference' => [
        // Versioned, keyed HMAC used to associate optional Stellar users with eSIMs.
        // Raw user IDs are never written to new simcard rows.
        'current_version' => (int) env('ESIM_USER_REF_HASH_VERSION', 1),

        'keys' => [
            1 => env('ESIM_USER_REF_HASH_KEY_V1', ''),
            2 => env('ESIM_USER_REF_HASH_KEY_V2', ''),
            3 => env('ESIM_USER_REF_HASH_KEY_V3', ''),
        ],
    ],

    'virtual_fulfillment' => [
        // Keep the application's global QUEUE_CONNECTION unchanged. Virtual-plan
        // included top-ups are isolated onto their own persistent connection/queue.
        'connection' => 'database',
        'queue' => 'virtual-esim-topups',
        'quota_queue' => 'virtual-esim-quota',
    ],

    'esimaccess' => [
        'base_url'    => env('ESIMACCESS_BASE_URL'),
        'access_code' => env('ESIMACCESS_ACCESS_CODE'),
        'secret_key'  => env('ESIMACCESS_SECRET_KEY'),
    ],

];
