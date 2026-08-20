<?php

namespace App\Http\Middleware;

use App\Models\AccessAccount;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Admin) {
            return $next($request);
        }

        if ($user instanceof AccessAccount) {
            if (! $user->isActive()) {
                $user->tokens()->delete();

                return response()->json(['message' => 'Access expired or revoked. Please request access again.'], 401);
            }

            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
