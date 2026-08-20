<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SimcardDataUsageAlertState extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'simcard_id',
        'threshold_percent',
        'state',
        'cycle',
        'last_checked_at',
        'last_check_failure_reason',
        'last_observed_total_bytes',
        'last_observed_remaining_bytes',
        'last_observed_order_usage',
        'last_observed_remaining_percent',
        'trigger_total_bytes',
        'trigger_remaining_bytes',
        'trigger_order_usage',
        'trigger_remaining_percent',
        'notified_at',
        'last_rearmed_at',
        'sms_status',
        'sms_attempted_at',
        'sms_sent_at',
        'sms_failure_reason',
        'email_status',
        'email_attempted_at',
        'email_sent_at',
        'email_failure_reason',
    ];

    protected $casts = [
        'threshold_percent' => 'integer',
        'cycle' => 'integer',
        'last_checked_at' => 'datetime',
        'last_observed_total_bytes' => 'integer',
        'last_observed_remaining_bytes' => 'integer',
        'last_observed_order_usage' => 'integer',
        'last_observed_remaining_percent' => 'decimal:2',
        'trigger_total_bytes' => 'integer',
        'trigger_remaining_bytes' => 'integer',
        'trigger_order_usage' => 'integer',
        'trigger_remaining_percent' => 'decimal:2',
        'notified_at' => 'datetime',
        'last_rearmed_at' => 'datetime',
        'sms_attempted_at' => 'datetime',
        'sms_sent_at' => 'datetime',
        'email_attempted_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];
}
