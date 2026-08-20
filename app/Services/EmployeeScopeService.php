<?php

namespace App\Services;

use App\Models\AccessAccount;
use App\Models\AccessProfile;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Query\Builder;

class EmployeeScopeService
{
    public function canWrite(mixed $user): bool
    {
        return $user instanceof Admin;
    }

    public function applyScope(Builder $query, mixed $user): Builder
    {
        if ($user instanceof Admin) {
            return $query;
        }

        // Employees may only use /me/* — never company registries
        if ($user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (! $user instanceof AccessAccount) {
            return $query->whereRaw('1 = 0');
        }

        $profile = $user->profile;

        $query->where('company', $profile->company);

        if ($profile->isHeadHr()) {
            $query->where('department', 'hr');
        }

        return $query;
    }

    public function getVisibleColumns(mixed $user): array
    {
        $base = [
            'id', 'first_name', 'last_name', 'birth_date', 'joined_at',
            'shift', 'grade', 'status', 'phone', 'email',
            'address', 'city', 'state', 'country', 'gender',
            'department', 'position', 'company', 'created_at', 'updated_at',
        ];

        if ($user instanceof Admin) {
            $base[] = 'salary';

            return $base;
        }

        if ($user instanceof AccessAccount && $user->profile?->isCeo()) {
            $base[] = 'salary';
        }

        return $base;
    }
}
