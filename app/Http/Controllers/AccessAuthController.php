<?php

namespace App\Http\Controllers;

use App\Models\AccessAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccessAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $account = AccessAccount::with('profile')
            ->where('username', $validated['username'])
            ->first();

        if (! $account || ! Hash::check($validated['password'], $account->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        if (! $account->isActive()) {
            throw ValidationException::withMessages([
                'username' => ['Access expired or revoked. Please request access again.'],
            ]);
        }

        $account->tokens()->delete();

        $token = $account->createToken(
            'access-token',
            ['access'],
            $account->expires_at,
        )->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user_type' => 'access',
                'expires_at' => $account->expires_at->toIso8601String(),
                'profile' => [
                    'label' => $account->profile->label,
                    'company' => $account->profile->company,
                    'role_type' => $account->profile->role_type,
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AccessAccount $account */
        $account = $request->user()->load('profile', 'request');

        return response()->json([
            'data' => [
                'user_type' => 'access',
                'username' => $account->username,
                'expires_at' => $account->expires_at->toIso8601String(),
                'profile' => $account->profile,
                'request' => [
                    'id' => $account->request->id,
                    'requester_name' => $account->request->requester_name,
                    'status' => $account->request->status,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
