<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRequest extends Model
{
    protected $fillable = [
        'user_id',
        'company',
        'type',
        'date',
        'related_day_id',
        'message',
        'status',
        'admin_comment',
        'resolved_by_admin_id',
        'resolved_by_access_account_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class, 'related_day_id');
    }
}
