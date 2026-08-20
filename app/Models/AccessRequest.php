<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessRequest extends Model
{
    protected $fillable = [
        'access_profile_id',
        'requester_name',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AccessProfile::class, 'access_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function account(): HasOne
    {
        return $this->hasOne(AccessAccount::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
