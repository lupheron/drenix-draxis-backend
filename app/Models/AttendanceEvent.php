<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    protected $fillable = [
        'user_id',
        'company',
        'external_key',
        'sheet_tab',
        'sheet_row',
        'employee_sheet_id',
        'employee_name',
        'action',
        'event_type',
        'occurred_at',
        'shift_date',
        'shift_time',
        'late_minutes',
        'status_raw',
        'notes',
        'didnt_come',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'shift_date' => 'date',
            'raw' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
