<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Simcard extends Model
{
    use HasUuids;

    protected $fillable = [
        'plan_id',
        'provider',
        'package_code',
        'external_order_id',
        'iccid',
        'state',
        'user_id',
        'account_ref',
    ];

    protected $casts = [
        'external_order_id' => 'encrypted',
        'iccid'             => 'encrypted',
    ];
}
