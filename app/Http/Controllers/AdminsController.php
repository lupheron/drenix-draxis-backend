<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminsController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = Admin::query()
            ->select('id', 'username', 'role', 'created_at', 'updated_at')
            ->orderBy('username')
            ->get();

        return response()->json(['data' => $admins]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:admins,username'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin'])],
        ]);

        $admin = Admin::create($validated);

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'role' => $admin->role,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ],
        ], 201);
    }

    /**
     * Update admin username (super_admin only).
     * Yes: one superadmin may rename another superadmin.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $admin = Admin::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('admins', 'username')->ignore($admin->id),
            ],
        ]);

        $admin->username = $validated['username'];
        $admin->save();

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'role' => $admin->role,
                'updated_at' => $admin->updated_at,
            ],
        ]);
    }

    /**
     * Reset admin password (super_admin only).
     * Yes: one superadmin may reset another superadmin's password.
     * Revokes all of that admin's sessions after change.
     */
    public function updatePassword(Request $request, int $id): JsonResponse
    {
        $admin = Admin::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $admin->password = $validated['password'];
        $admin->save();
        $admin->tokens()->delete();

        return response()->json(['message' => 'Password updated. All sessions revoked.']);
    }

    /**
     * Revoke all sessions for one admin.
     * If targeting the caller, keeps their *current* session.
     */
    public function destroySessions(Request $request, int $id): JsonResponse
    {
        $admin = Admin::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        /** @var Admin $caller */
        $caller = $request->user();
        $currentId = $caller->currentAccessToken()?->id;

        $query = $admin->tokens();

        if ((int) $caller->id === (int) $admin->id && $currentId) {
            $query->where('id', '!=', $currentId);
        }

        $revoked = $query->delete();

        return response()->json([
            'message' => 'Sessions revoked',
            'revoked' => $revoked,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $admin = Admin::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found.'], 404);
        }

        if ($admin->isSuperAdmin()) {
            return response()->json(['message' => 'Super admin cannot be removed.'], 403);
        }

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(null, 204);
    }
}
