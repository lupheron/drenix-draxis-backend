<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = $this->getAllowedOrigins();
        $origin = $request->header('Origin');

        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-XSRF-TOKEN, X-CSRF-TOKEN, X-Requested-With, Accept, Origin, Authorization',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
        ];

        if (in_array($origin, $allowedOrigins)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        } else if (in_array('*', $allowedOrigins)) {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        if ($request->isMethod('OPTIONS')) {
            return response()->json(['message' => 'Preflight request successful'], 200)->withHeaders($headers);
        }

        $response = $next($request);
        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    private function getAllowedOrigins(): array
    {
        $origins = env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:3000,http://127.0.0.1:3000,http://localhost:3001,http://127.0.0.1:3001'
        );

        if(app()->environment('production')) {
            $origins = env('CORS_ALLOWED_ORIGINS_PRODUCTION', 'https://your-production-domain.com');
        }

        return array_map('trim', explode(',', $origins));
    }
}