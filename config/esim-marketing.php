<?php

return [
    'refund_offer' => [
        'enabled' => (bool) env('STELLAR_ESIM_MARKETING_REFUND_ENABLED', true),
        'product' => env('STELLAR_ESIM_MARKETING_REFUND_PRODUCT', 'stellar-esim-marketing-reward'),
        'event' => env('STELLAR_ESIM_MARKETING_REFUND_EVENT', 'esim_first_usage_detected'),
        'refund_amount' => (float) env('STELLAR_ESIM_MARKETING_REFUND_AMOUNT', 10),
        'discount_percentage' => (int) env('STELLAR_ESIM_MARKETING_REFUND_DISCOUNT_PERCENTAGE', 20),
        'support_email' => env('STELLAR_ESIM_MARKETING_REFUND_SUPPORT_EMAIL', 'info@stellarsecurity.com'),
        'retry_after_minutes' => (int) env('STELLAR_ESIM_MARKETING_REFUND_RETRY_AFTER_MINUTES', 15),
        'batch_size' => (int) env('STELLAR_ESIM_MARKETING_REFUND_BATCH_SIZE', 100),
    ],
];
