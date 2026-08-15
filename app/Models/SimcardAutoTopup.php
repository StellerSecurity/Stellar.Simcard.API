<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SimcardAutoTopup extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'simcard_id',
        'parent_commerce_order_id',
        'parent_commerce_order_item_id',
        'commerce_unit',
        'enabled',
        'state',
        'trigger_percent',
        'preferred_data_bytes',
        'preferred_duration_days',
        'cycle',
        'last_trigger_total_bytes',
        'last_trigger_remaining_bytes',
        'last_trigger_order_usage',
        'last_triggered_at',
        'last_success_at',
        'last_rearmed_at',
        'failure_reason',
        'meta',
    ];

    protected $casts = [
        'commerce_unit' => 'integer',
        'enabled' => 'boolean',
        'trigger_percent' => 'integer',
        'preferred_data_bytes' => 'integer',
        'preferred_duration_days' => 'integer',
        'cycle' => 'integer',
        'last_trigger_total_bytes' => 'integer',
        'last_trigger_remaining_bytes' => 'integer',
        'last_trigger_order_usage' => 'integer',
        'last_triggered_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_rearmed_at' => 'datetime',
        'meta' => 'array',
    ];
}
