<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SimcardActionLink extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'simcard_id',
        'action',
        'token_hash',
        'expires_at',
        'used_at',
        'metadata_redacted',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'metadata_redacted' => 'array',
    ];

    protected $hidden = [
        'token_hash',
    ];
}
