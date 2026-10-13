<?php

namespace App\Http\Middleware;

use App\Exceptions\SupabaseAuthException;
use App\Services\SupabaseJwtVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySupabaseJwt
{
    public function __construct(private SupabaseJwtVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization', '');
        if (! preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD', $authorization, $matches)) {
            return response()->json(['error' => 'UNAUTHORIZED'], 401);
        }
        try {
            $request->attributes->set('supabase_user', $this->verifier->verify($matches[1]));
        } catch (SupabaseAuthException $exception) {
            return response()->json(['error' => $exception->getMessage()], $exception->status);
        }

        return $next($request);
    }
}
