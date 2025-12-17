<?php

return [

    'crypto' => [
        // Used to compute HMAC(plan_id) for DB lookup.
        'hash_key' => env('ESIM_PLAN_HASH_KEY', ''),

        // Used as master key to derive per-plan data encryption keys.
        'master_key' => env('ESIM_PLAN_MASTER_KEY', ''),
    ],

    'esimaccess' => [
        'base_url'    => env('ESIMACCESS_BASE_URL'),
        'access_code' => env('ESIMACCESS_ACCESS_CODE'),
        'secret_key'  => env('ESIMACCESS_SECRET_KEY'),
    ],

];
