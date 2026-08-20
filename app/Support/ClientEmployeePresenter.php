<?php

namespace App\Support;

class ClientEmployeePresenter
{
    /**
     * Client-portal-safe employee shape (never salary / admin flags).
     */
    public static function present(object $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username ?? null,
            'first_name' => $user->first_name ?? null,
            'last_name' => $user->last_name ?? null,
            'phone' => $user->phone ?? null,
            'email' => $user->email ?? null,
            'shift' => $user->shift ?? null,
            'department' => $user->department ?? null,
            'position' => $user->position ?? null,
            'company' => $user->company ?? null,
        ];
    }
}
