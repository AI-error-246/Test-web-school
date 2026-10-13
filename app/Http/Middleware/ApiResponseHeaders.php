<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = self::apply($next($request));
        if ($request->is('password/*', 'forgot-password', 'reset-password', 'reset-password/*', 'admin/mfa', 'admin/mfa/*', 'candidate/notifications/*/email')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }

    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $policy = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
        if (! in_array($policy, $response->headers->all('Content-Security-Policy'), true)) {
            $response->headers->set('Content-Security-Policy', $policy, false);
        }
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
