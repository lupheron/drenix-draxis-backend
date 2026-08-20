<?php

namespace App\Services;

use App\Models\AccessAccount;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessRequestService
{
    public function approve(AccessRequest $request, Admin $admin): array
    {
        if (! $request->isPending()) {
            throw new \InvalidArgumentException('Only pending requests can be approved.');
        }

        return DB::transaction(function () use ($request, $admin) {
            $profile = $request->profile;
            $plainPassword = Str::password(12);
            $username = $this->generateUsername($profile);
            $expiresAt = now()->addMonths(3);

            $account = AccessAccount::create([
                'access_request_id' => $request->id,
                'access_profile_id' => $profile->id,
                'username' => $username,
                'password' => $plainPassword,
                'expires_at' => $expiresAt,
            ]);

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            return [
                'request' => $request->fresh(['profile', 'reviewer', 'account']),
                'credentials' => [
                    'username' => $username,
                    'password' => $plainPassword,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ];
        });
    }

    public function deny(AccessRequest $request, Admin $admin): AccessRequest
    {
        if (! $request->isPending()) {
            throw new \InvalidArgumentException('Only pending requests can be denied.');
        }

        $request->update([
            'status' => 'denied',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        return $request->fresh(['profile', 'reviewer']);
    }

    public function revoke(AccessRequest $request, Admin $admin): AccessRequest
    {
        if (! $request->isApproved()) {
            throw new \InvalidArgumentException('Only approved requests can be revoked.');
        }

        return DB::transaction(function () use ($request, $admin) {
            $request->account?->tokens()->delete();
            $request->account?->update(['revoked_at' => now()]);

            $request->update([
                'status' => 'revoked',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $request->fresh(['profile', 'reviewer', 'account']);
        });
    }

    public function expireDueAccounts(): int
    {
        $expired = AccessRequest::query()
            ->where('status', 'approved')
            ->where('expires_at', '<=', now())
            ->with('account')
            ->get();

        foreach ($expired as $request) {
            $request->account?->tokens()->delete();
            $request->account?->update(['revoked_at' => now()]);
            $request->update(['status' => 'expired']);
        }

        return $expired->count();
    }

    private function generateUsername(AccessProfile $profile): string
    {
        $prefix = strtolower($profile->company).'.'.($profile->isCeo() ? 'ceo' : 'hr');
        $suffix = Str::lower(Str::random(6));

        do {
            $username = "{$prefix}.{$suffix}";
            $suffix = Str::lower(Str::random(6));
        } while (AccessAccount::where('username', $username)->exists());

        return $username;
    }
}
