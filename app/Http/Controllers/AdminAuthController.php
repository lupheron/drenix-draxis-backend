<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Support\DeviceLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('username', $validated['username'])->first();

        if (! $admin || ! Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        // Multi-device: keep other sessions; store IP / UA on this token
        $newToken = $admin->createToken(
            'admin-token',
            ['admin'],
            now()->addDays(7),
        );

        $ua = $request->userAgent();
        $newToken->accessToken->forceFill([
            'ip' => $request->ip(),
            'user_agent' => $ua,
            'device_label' => DeviceLabel::fromUserAgent($ua),
            'last_used_at' => now(),
        ])->save();

        return response()->json([
            'data' => [
                'token' => $newToken->plainTextToken,
                'token_type' => 'Bearer',
                'role' => $admin->role,
                'user_type' => 'admin',
                'admin' => [
                    'id' => $admin->id,
                    'username' => $admin->username,
                    'role' => $admin->role,
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        return response()->json([
            'data' => [
                'user_type' => 'admin',
                'id' => $admin->id,
                'username' => $admin->username,
                'role' => $admin->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
