<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Simcard extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $casts = [
        'purchased_on' => 'date:Y-m-d',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'first_used_at' => 'datetime',
        'marketing_refund_notification_attempted_at' => 'datetime',
        'marketing_refund_notification_queued_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'email_opt_in_at' => 'datetime',
        'user_ref_version' => 'integer',
        'user_linked_at' => 'datetime',
        'install_payload_captured_at' => 'datetime',
        'install_payload_crypto_version' => 'integer',
    ];

    protected $fillable = [
        'plan_id_hash',
        'provider',
        'provider_account',
        'package_code',
        'external_order_id_enc',
        'external_order_id_hash',
        'install_payload_enc',
        'install_payload_crypto_version',
        'install_payload_captured_at',
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
        'first_used_at',
        'marketing_refund_notification_attempted_at',
        'marketing_refund_notification_queued_at',
        'last_webhook_at',
        'user_ref',
        'user_ref_version',
        'user_linked_at',
        'user_link_source',
        'commerce_order_id',
        'commerce_order_item_id',
        'commerce_unit',
        'idempotency_key',
        'email_enc',
        'email_hash',
        'email_opt_in_at',
        'email_source',
        'purchased_on',
    ];

    protected $hidden = [
        'external_order_id_enc',
        'external_order_id_hash',
        'install_payload_enc',
        'install_payload_crypto_version',
        'install_payload_captured_at',
        'iccid_enc',
        'iccid_hash',
        'plan_id_hash',
        'email_enc',
        'email_hash',
        'user_id',
        'user_ref',
        'user_ref_version',
        'user_linked_at',
        'user_link_source',
    ];
}
