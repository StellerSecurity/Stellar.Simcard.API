<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Simcard extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'plan_id_hash',
        'provider',
        'package_code',
        'external_order_id_enc',
        'iccid_enc',
        'state',
        'user_id',
        'account_ref',
    ];
}
