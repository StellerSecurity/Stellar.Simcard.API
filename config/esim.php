<?php

return [

    'crypto' => [
        // Used to compute HMAC(plan_id) for DB lookup.
        'hash_key' => env('ESIM_PLAN_HASH_KEY', ''),

        // Used as master key to derive per-plan data encryption keys.
        'master_key' => env('ESIM_PLAN_MASTER_KEY', ''),
    ],

];
