<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WholesaleWebhookRelay extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'provider',
        'payload_encrypted',
        'content_type',
        'commerce_order_id',
        'commerce_order_item_id',
        'commerce_unit',
        'status',
        'attempts',
        'response_status',
        'last_error',
        'received_at',
        'last_attempt_at',
        'next_attempt_at',
        'delivered_at',
    ];

    protected $hidden = [
        'payload_encrypted',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'response_status' => 'integer',
        'commerce_unit' => 'integer',
        'received_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
