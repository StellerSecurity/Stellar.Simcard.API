<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SimcardSupportReplacement extends Model
{
    use HasUuids;

    protected $fillable = [
        'old_simcard_id', 'new_simcard_id', 'idempotency_key', 'new_plan_id_enc',
        'status', 'last_error', 'cancelled_old_at', 'completed_at',
    ];

    protected $hidden = ['new_plan_id_enc'];

    protected function casts(): array
    {
        return [
            'cancelled_old_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
