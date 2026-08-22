<?php

namespace App\Services\Attendance;

use App\Models\AccessAccount;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AttendanceScopeService
{
    public function canManage(mixed $actor): bool
    {
        return $actor instanceof Admin || $actor instanceof AccessAccount;
    }

    public function applyUserScope(QueryBuilder|Builder $query, mixed $actor): QueryBuilder|Builder
    {
        if ($actor instanceof Admin) {
            return $query;
        }

        if ($actor instanceof AccessAccount) {
            $profile = $actor->profile;
            $query->where('company', $profile->company);

            if ($profile->isHeadHr()) {
                $query->where('department', 'hr');
            }

            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function canAccessEmployee(mixed $actor, object $employee): bool
    {
        if ($actor instanceof Admin) {
            return true;
        }

        if ($actor instanceof AccessAccount) {
            $profile = $actor->profile;
            if ($profile->company !== $employee->company) {
                return false;
            }

            if ($profile->isHeadHr()) {
                return strtolower((string) $employee->department) === 'hr';
            }

            return true;
        }

        return false;
    }

    public function notificationRecipients(string $company, User $employee): array
    {
        $recipients = [];

        foreach (Admin::query()->get() as $admin) {
            $recipients[] = ['type' => Admin::class, 'id' => $admin->id];
        }

        $accounts = AccessAccount::query()
            ->whereHas('profile', function ($q) use ($company) {
                $q->where('company', $company)
                    ->whereIn('role_type', ['ceo', 'head_hr']);
            })
            ->with('profile')
            ->get();

        foreach ($accounts as $account) {
            if ($account->profile?->isHeadHr() && strtolower((string) $employee->department) !== 'hr') {
                continue;
            }

            $recipients[] = ['type' => AccessAccount::class, 'id' => $account->id];
        }

        return $recipients;
    }
}
