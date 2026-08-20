<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminWriter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Admin) {
            return response()->json(['message' => 'Only admins can modify employee data.'], 403);
        }

        return $next($request);
    }
}
