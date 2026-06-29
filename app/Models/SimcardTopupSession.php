<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SimcardTopupSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'simcard_id',
        'action_link_id',
        'package_code',
        'package_name',
        'data_bytes',
        'duration_days',
        'price_cents',
        'currency',
        'status',
        'idempotency_key',
        'commerce_order_id',
        'commerce_order_item_id',
        'supplier_reference',
        'failure_reason',
        'meta',
        'requested_at',
        'paid_at',
        'fulfilled_at',
    ];

    protected $casts = [
        'data_bytes' => 'integer',
        'duration_days' => 'integer',
        'price_cents' => 'integer',
        'meta' => 'array',
        'requested_at' => 'datetime',
        'paid_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];
}
