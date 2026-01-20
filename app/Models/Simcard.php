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
    ];

    protected $fillable = [
        'plan_id_hash',
        'provider',
        'package_code',
        'external_order_id_enc',
        'state',
        'user_id',
        'purchased_on'
    ];

    protected $hidden = [
        'external_order_id_enc',
        'plan_id_hash',
    ];

}
