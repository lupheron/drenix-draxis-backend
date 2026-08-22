<?php

namespace App\Http\Middleware;

use App\Models\AccessAccount;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAttendanceManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Admin) {
            return $next($request);
        }

        if ($user instanceof AccessAccount) {
            $role = $user->profile?->role_type;
            if (in_array($role, ['ceo', 'head_hr'], true)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Insufficient permissions for attendance management.'], 403);
    }
}
