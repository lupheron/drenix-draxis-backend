<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    protected $fillable = [
        'user_id',
        'company',
        'external_id',
        'started_at',
        'duration_seconds',
        'direction',
        'result',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'raw' => 'array',
        ];
    }
}
