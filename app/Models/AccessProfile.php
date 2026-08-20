<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessProfile extends Model
{
    protected $fillable = [
        'label',
        'company',
        'role_type',
    ];

    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class);
    }

    public function accessAccounts(): HasMany
    {
        return $this->hasMany(AccessAccount::class);
    }

    public function isCeo(): bool
    {
        return $this->role_type === 'ceo';
    }

    public function isHeadHr(): bool
    {
        return $this->role_type === 'head_hr';
    }
}
