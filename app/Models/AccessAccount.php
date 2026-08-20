<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AccessAccount extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'access_request_id',
        'access_profile_id',
        'username',
        'password',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class, 'access_request_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AccessProfile::class, 'access_profile_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
