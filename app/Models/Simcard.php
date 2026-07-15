<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Simcard extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $casts = [
        'purchased_on' => 'date:Y-m-d',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'email_opt_in_at' => 'datetime',
    ];

    protected $fillable = [
        'plan_id_hash',
        'provider',
        'package_code',
        'external_order_id_enc',
        'external_order_id_hash',
        'iccid_enc',
        'iccid_hash',
        'iccid_last4',
        'state',
        'esim_status',
        'smdp_status',
        'data_status',
        'validity_status',
        'total_volume',
        'order_usage',
        'remaining_volume',
        'remaining_validity',
        'expires_at',
        'activated_at',
        'last_webhook_at',
        'user_id',
        'commerce_order_id',
        'commerce_order_item_id',
        'commerce_unit',
        'idempotency_key',
        'email_enc',
        'email_hash',
        'email_opt_in_at',
        'email_source',
        'purchased_on'
    ];

    protected $hidden = [
        'external_order_id_enc',
        'external_order_id_hash',
        'iccid_enc',
        'iccid_hash',
        'plan_id_hash',
        'email_enc',
        'email_hash',
    ];
}
