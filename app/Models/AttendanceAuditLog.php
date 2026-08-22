<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceAuditLog extends Model
{
    protected $fillable = [
        'attendance_day_id',
        'attendance_request_id',
        'user_id',
        'actor_type',
        'actor_id',
        'action',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
