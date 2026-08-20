<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin || ! method_exists($admin, 'isSuperAdmin') || ! $admin->isSuperAdmin()) {
            return response()->json(['message' => 'Super admin access required.'], 403);
        }

        return $next($request);
    }
}
