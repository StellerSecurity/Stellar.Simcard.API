<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EsimWebhookEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $casts = [
        'payload_redacted' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected $fillable = [
        'id',
        'provider',
        'notify_type',
        'idempotency_key',
        'simcard_id',
        'external_order_id_hash',
        'transaction_id_hash',
        'transaction_id_last4',
        'iccid_hash',
        'iccid_last4',
        'status',
        'payload_redacted',
        'error_code',
        'error_message',
        'received_at',
        'processed_at',
    ];
}
