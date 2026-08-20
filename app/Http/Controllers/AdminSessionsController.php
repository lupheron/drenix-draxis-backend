<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSessionsController extends Controller
{
    /**
     * List active admin Sanctum sessions (super_admin only).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'admin_id' => ['nullable', 'integer', 'exists:admins,id'],
        ]);

        $currentId = $request->user()->currentAccessToken()?->id;

        $query = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->with('tokenable')
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at');

        if (! empty($validated['admin_id'])) {
            $query->where('tokenable_id', $validated['admin_id']);
        }

        $data = $query->get()->map(function (PersonalAccessToken $token) use ($currentId) {
            /** @var Admin|null $admin */
            $admin = $token->tokenable;

            return [
                'id' => $token->id,
                'admin_id' => $token->tokenable_id,
                'admin_username' => $admin?->username,
                'admin_role' => $admin?->role,
                'ip' => $token->ip,
                'user_agent' => $token->user_agent,
                'device_label' => $token->device_label,
                'last_used_at' => optional($token->last_used_at)?->toIso8601String(),
                'created_at' => optional($token->created_at)?->toIso8601String(),
                'is_current' => $currentId !== null && (int) $token->id === (int) $currentId,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Revoke one session by personal_access_tokens.id
     */
    public function destroy(int $id): JsonResponse
    {
        $token = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('id', $id)
            ->first();

        if (! $token) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Session revoked']);
    }

    /**
     * Revoke all of *my* other devices; keep current session.
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $currentId = $admin->currentAccessToken()?->id;

        $query = $admin->tokens();
        if ($currentId) {
            $query->where('id', '!=', $currentId);
        }
        $revoked = $query->delete();

        return response()->json([
            'message' => 'Other sessions revoked',
            'revoked' => $revoked,
        ]);
    }
}
