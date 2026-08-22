<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDay extends Model
{
    protected $fillable = [
        'user_id',
        'company',
        'date',
        'status',
        'check_in_at',
        'check_out_at',
        'break_at',
        'late_minutes',
        'shift_start',
        'shift_end',
        'sheet_note',
        'admin_note',
        'is_manual_override',
        'overridden_by_admin_id',
        'overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'break_at' => 'datetime',
            'is_manual_override' => 'boolean',
            'overridden_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'user_id', 'user_id');
    }
}
