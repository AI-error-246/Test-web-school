<?php

namespace App\Services;

use App\Exceptions\SupabaseAuthException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SupabaseJwtVerifier
{
    /** @return array{id: string, email: ?string} */
    public function verify(string $token): array
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            throw new SupabaseAuthException('AUTH_UNAVAILABLE', 503);
        }
        $jwksUrl = $url.'/auth/v1/.well-known/jwks.json';
        if (config('supabase.jwks_url') && config('supabase.jwks_url') !== $jwksUrl) {
            throw new SupabaseAuthException('AUTH_UNAVAILABLE', 503);
        }
        $segments = explode('.', $token);
        $header = count($segments) === 3 ? json_decode(JWT::urlsafeB64Decode($segments[0]), true) : null;
        if (strlen($token) > 8192 || ! is_array($header) || ! in_array($header['alg'] ?? null, ['ES256', 'RS256'], true) || ! is_string($header['kid'] ?? null) || $header['kid'] === '' || strlen($header['kid']) > 128) {
            throw new SupabaseAuthException('INVALID_TOKEN', 401);
        }
        $cacheKey = 'supabase.jwks.'.hash('sha256', $jwksUrl);
        $keys = Cache::remember($cacheKey, 300, fn (): array => $this->fetchKeys($jwksUrl));
        if (! in_array($header['kid'], array_column($keys['keys'], 'kid'), true) && Cache::add($cacheKey.'.refresh', true, 30)) {
            $keys = $this->fetchKeys($jwksUrl);
            Cache::put($cacheKey, $keys, 300);
        }
        try {
            $claims = JWT::decode($token, JWK::parseKeySet($keys));
        } catch (Throwable) {
            throw new SupabaseAuthException('INVALID_TOKEN', 401);
        }
        $audience = $claims->aud ?? null;
        if (($claims->iss ?? null) !== $url.'/auth/v1' || ! (is_string($audience) ? $audience === 'authenticated' : is_array($audience) && in_array('authenticated', $audience, true)) || ! is_int($claims->exp ?? null) || ! is_string($claims->sub ?? null) || ! Str::isUuid($claims->sub)) {
            throw new SupabaseAuthException('INVALID_TOKEN', 401);
        }
        if (($claims->role ?? null) !== 'authenticated') {
            throw new SupabaseAuthException('USER_REQUIRED', 403);
        }

        return ['id' => $claims->sub, 'email' => is_string($claims->email ?? null) ? $claims->email : null];
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function fetchKeys(string $url): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(5)->withoutRedirecting()->get($url);
            $keys = $response->json('keys');
            if (! $response->successful() || ! is_array($keys)) {
                throw new \RuntimeException('Invalid JWKS');
            }
            $keys = array_values(array_filter($keys, fn (mixed $key): bool => is_array($key)
                && in_array($key['alg'] ?? null, ['RS256', 'ES256'], true)
                && in_array($key['kty'] ?? null, ['RSA', 'EC'], true)
                && ($key['use'] ?? 'sig') === 'sig'));
            if ($keys === []) {
                throw new \RuntimeException('Empty JWKS');
            }

            return ['keys' => $keys];
        } catch (Throwable) {
            throw new SupabaseAuthException('AUTH_UNAVAILABLE', 503);
        }
    }
}
