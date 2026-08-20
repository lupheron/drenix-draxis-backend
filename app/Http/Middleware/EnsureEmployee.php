<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Employee access required.'], 403);
        }

        if (empty($user->username) || empty($user->password)) {
            $user->tokens()->delete();

            return response()->json(['message' => 'Portal credentials not configured.'], 401);
        }

        return $next($request);
    }
}
