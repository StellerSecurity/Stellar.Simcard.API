<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SimcardAutoTopupAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'auto_topup_id',
        'cycle',
        'attempt_key',
        'status',
        'observed_total_bytes',
        'observed_remaining_bytes',
        'observed_order_usage',
        'observed_remaining_percent',
        'topup_session_id',
        'commerce_order_id',
        'stripe_payment_intent_id',
        'failure_reason',
        'meta',
        'payment_requested_at',
        'fulfilled_at',
        'notification_attempted_at',
        'notification_sent_at',
        'notification_failure_reason',
        'sms_attempted_at',
        'sms_sent_at',
        'sms_failure_reason',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'observed_total_bytes' => 'integer',
        'observed_remaining_bytes' => 'integer',
        'observed_order_usage' => 'integer',
        'observed_remaining_percent' => 'float',
        'meta' => 'array',
        'payment_requested_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'notification_attempted_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'sms_attempted_at' => 'datetime',
        'sms_sent_at' => 'datetime',
    ];
}
