<?php

namespace App\Http\Middleware;

use App\Models\AccessAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccessAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof AccessAccount) {
            return response()->json(['message' => 'Guest access login required.'], 403);
        }

        if (! $user->isActive()) {
            $user->tokens()->delete();

            return response()->json(['message' => 'Access expired or revoked. Please request access again.'], 401);
        }

        return $next($request);
    }
}
