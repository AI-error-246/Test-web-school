<?php

namespace App\Services;

use App\Exceptions\SupabaseRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;

class SupabaseAuthService
{
    /**
     * Metadata describes signup intent only; application roles must come from the application database.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{user: array<string, mixed>, access_token: ?string, refresh_token: ?string, expires_in: ?int, expires_at: ?int, token_type: ?string}
     */
    public function signup(string $email, #[SensitiveParameter] string $password, array $metadata = []): array
    {
        $data = [];
        foreach (['name', 'full_name'] as $field) {
            if (is_string($metadata[$field] ?? null)) {
                $data[$field] = $metadata[$field];
            }
        }
        if (in_array($metadata['account_type'] ?? null, ['candidate', 'employer'], true)) {
            $data['account_type'] = $metadata['account_type'];
        }

        $response = $this->request('POST', '/signup', ['email' => $email, 'password' => $password, 'data' => (object) $data]);

        return $this->session($response, true);
    }

    /** @return array{user: array<string, mixed>, access_token: ?string, refresh_token: ?string, expires_in: ?int, expires_at: ?int, token_type: ?string} */
    public function signIn(string $email, #[SensitiveParameter] string $password): array
    {
        return $this->session($this->request('POST', '/token?grant_type=password', ['email' => $email, 'password' => $password]));
    }

    /** @return array{user: array<string, mixed>, access_token: ?string, refresh_token: ?string, expires_in: ?int, expires_at: ?int, token_type: ?string} */
    public function refresh(#[SensitiveParameter] string $refreshToken): array
    {
        $this->validateToken($refreshToken);

        return $this->session($this->request('POST', '/token?grant_type=refresh_token', ['refresh_token' => $refreshToken]));
    }

    public function signOut(#[SensitiveParameter] string $accessToken): void
    {
        $this->validateToken($accessToken);
        $this->request('POST', '/logout?scope=local', [], $accessToken);
    }

    public function requestPasswordRecovery(string $email, string $codeChallenge): void
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $codeChallenge)) {
            throw new SupabaseRequestException('AUTH_FAILED', 401);
        }

        $this->request('POST', '/recover?redirect_to='.rawurlencode($this->recoveryRedirect()), [
            'email' => $email,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 's256',
        ]);
    }

    /** @return array{user: array<string, mixed>, access_token: ?string, refresh_token: ?string, expires_in: ?int, expires_at: ?int, token_type: ?string} */
    public function exchangeRecoveryCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): array
    {
        if (! Str::isUuid($code) || ! preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier)) {
            throw new SupabaseRequestException('AUTH_FAILED', 401);
        }

        return $this->session($this->request('POST', '/token?grant_type=pkce', [
            'auth_code' => $code,
            'code_verifier' => $verifier,
        ]));
    }

    /** @return array<string, mixed> */
    public function updateRecoveredPassword(#[SensitiveParameter] string $accessToken, #[SensitiveParameter] string $password): array
    {
        $this->validateToken($accessToken);
        $user = $this->request('PUT', '/user', ['password' => $password], $accessToken)->json();
        $this->validateUser($user);

        return $user;
    }

    public function signOutAll(#[SensitiveParameter] string $accessToken): void
    {
        $this->validateToken($accessToken);
        $this->request('POST', '/logout?scope=global', [], $accessToken);
    }

    /** @return array<string, mixed> */
    public function user(#[SensitiveParameter] string $accessToken): array
    {
        $this->validateToken($accessToken);
        $user = $this->request('GET', '/user', [], $accessToken)->json();
        $this->validateUser($user);

        return $user;
    }

    /** @return list<array{id: string, friendly_name: string}> */
    public function mfaFactors(#[SensitiveParameter] string $accessToken): array
    {
        $user = $this->user($accessToken);
        $factors = $user['factors'] ?? [];
        if (! is_array($factors) || ! array_is_list($factors)) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        $verified = [];
        foreach ($factors as $factor) {
            if (! is_array($factor) || ! is_string($factor['id'] ?? null) || ! Str::isUuid($factor['id'])
                || ! is_string($factor['factor_type'] ?? null)
                || ! in_array($factor['status'] ?? null, ['verified', 'unverified'], true)) {
                throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
            }
            if ($factor['factor_type'] === 'totp' && $factor['status'] === 'verified') {
                $verified[] = ['id' => $factor['id'], 'friendly_name' => is_string($factor['friendly_name'] ?? null)
                    ? mb_substr($factor['friendly_name'], 0, 100) : 'Authenticator'];
            }
        }

        return $verified;
    }

    /**
     * QR content is a provider SVG; callers must use an image data URL, never inline it as HTML.
     *
     * @return array{id: string, secret: string, qr_code: string}
     */
    public function enrollTotp(#[SensitiveParameter] string $accessToken): array
    {
        $this->validateToken($accessToken);
        $factor = $this->request('POST', '/factors', [
            'factor_type' => 'totp',
            'friendly_name' => 'InternBridge Admin '.Str::uuid(),
            'issuer' => 'InternBridge',
        ], $accessToken)->json();
        if (! is_array($factor) || ! is_string($factor['id'] ?? null) || ! Str::isUuid($factor['id'])
            || ($factor['type'] ?? null) !== 'totp' || ! is_array($factor['totp'] ?? null)
            || ! is_string($factor['totp']['secret'] ?? null) || ! preg_match('/\A[A-Z2-7]{16,256}\z/', $factor['totp']['secret'])
            || ! is_string($factor['totp']['qr_code'] ?? null) || $factor['totp']['qr_code'] === ''
            || strlen($factor['totp']['qr_code']) > 1024 * 1024) {
            logger()->warning('admin_mfa_enrollment_invalid_response', [
                'id_valid' => is_string($factor['id'] ?? null) && Str::isUuid($factor['id']),
                'type_valid' => ($factor['type'] ?? null) === 'totp',
                'secret_valid' => is_string($factor['totp']['secret'] ?? null) && (bool) preg_match('/\A[A-Z2-7]{16,256}\z/', $factor['totp']['secret']),
                'qr_bytes' => is_string($factor['totp']['qr_code'] ?? null) ? strlen($factor['totp']['qr_code']) : null,
            ]);
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        return ['id' => $factor['id'], 'secret' => $factor['totp']['secret'], 'qr_code' => $factor['totp']['qr_code']];
    }

    /** @return array{user: array<string, mixed>, access_token: ?string, refresh_token: ?string, expires_in: ?int, expires_at: ?int, token_type: ?string} */
    public function verifyTotp(#[SensitiveParameter] string $accessToken, string $factorId, #[SensitiveParameter] string $code): array
    {
        $this->validateToken($accessToken);
        if (! Str::isUuid($factorId) || ! preg_match('/\A[0-9]{6}\z/', $code)) {
            throw new SupabaseRequestException('AUTH_FAILED', 401);
        }

        $challenge = $this->request('POST', '/factors/'.$factorId.'/challenge', [], $accessToken)->json();
        if (! is_array($challenge) || ! is_string($challenge['id'] ?? null) || ! Str::isUuid($challenge['id'])
            || ($challenge['type'] ?? null) !== 'totp' || ! is_int($challenge['expires_at'] ?? null)
            || $challenge['expires_at'] <= now()->timestamp) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        $session = $this->session($this->request('POST', '/factors/'.$factorId.'/verify', [
            'challenge_id' => $challenge['id'], 'code' => $code,
        ], $accessToken));
        if (! self::isAal2($session['access_token'], $session['user']['id'])) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        return $session;
    }

    /**
     * Checks claims of credentials obtained from Supabase on the server and kept in the encrypted session.
     * This does not verify a signature and must never authenticate a token supplied by a caller.
     */
    public static function isAal2(#[SensitiveParameter] string $accessToken, string $userId): bool
    {
        if (! Str::isUuid($userId) || strlen($accessToken) > 16384
            || ! preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $accessToken)) {
            return false;
        }

        $parts = explode('.', $accessToken);
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true, 16);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, 16);

        return is_array($header) && in_array($header['alg'] ?? null, ['HS256', 'RS256', 'ES256'], true)
            && is_array($claims) && ($claims['aal'] ?? null) === 'aal2' && ($claims['sub'] ?? null) === $userId
            && is_int($claims['exp'] ?? null) && $claims['exp'] > now()->timestamp;
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, string $path, #[SensitiveParameter] array $payload = [], #[SensitiveParameter] ?string $token = null): Response
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $parts = parse_url($url);
        $key = config('supabase.publishable_key');
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! empty($parts['path']) || ! is_string($key) || trim($key) === '' || preg_match('/[\r\n]/', $key)) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        $request = Http::acceptJson()->asJson()->withHeaders(['apikey' => $key])
            ->connectTimeout(3)->timeout(10)->withoutRedirecting();
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->send($method, $url.'/auth/v1'.$path, $method === 'GET' ? [] : ['json' => $payload === [] ? (object) [] : $payload]);
        } catch (ConnectionException) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        if (! $response->successful()) {
            $error = $response->json('error_code');
            if ($response->status() === 429) {
                throw new SupabaseRequestException('AUTH_RATE_LIMITED', 429);
            }
            if ($response->serverError() || $response->redirect()) {
                throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
            }
            if ($error === 'email_not_confirmed') {
                throw new SupabaseRequestException('EMAIL_NOT_CONFIRMED', 403);
            }
            throw new SupabaseRequestException($path === '/signup' ? 'SIGNUP_FAILED' : 'AUTH_FAILED', $path === '/signup' ? 422 : 401);
        }

        return $response;
    }

    /** @return array{user: array<string, mixed>, access_token: ?string, refresh_token: ?string, expires_in: ?int, expires_at: ?int, token_type: ?string} */
    private function session(Response $response, bool $allowConfirmation = false): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }
        $user = $payload['user'] ?? ($allowConfirmation ? $payload : null);
        $this->validateUser($user);

        if ($allowConfirmation && ! isset($payload['access_token']) && ! isset($payload['refresh_token'])) {
            return ['user' => $user, 'access_token' => null, 'refresh_token' => null, 'expires_in' => null, 'expires_at' => null, 'token_type' => null];
        }
        if (! is_string($payload['access_token'] ?? null) || $payload['access_token'] === ''
            || ! is_string($payload['refresh_token'] ?? null) || $payload['refresh_token'] === ''
            || ! is_int($payload['expires_in'] ?? null) || $payload['expires_in'] <= 0) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        return ['user' => $user, 'access_token' => $payload['access_token'], 'refresh_token' => $payload['refresh_token'],
            'expires_in' => $payload['expires_in'], 'expires_at' => now()->timestamp + $payload['expires_in'], 'token_type' => 'bearer'];
    }

    private function validateToken(#[SensitiveParameter] string $token): void
    {
        if ($token === '' || strlen($token) > 16384 || preg_match('/\s/', $token)) {
            throw new SupabaseRequestException('AUTH_FAILED', 401);
        }
    }

    private function validateUser(mixed $user): void
    {
        if (! is_array($user) || ! is_string($user['id'] ?? null) || ! Str::isUuid($user['id'])) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }
    }

    private function recoveryRedirect(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);
        $localHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && app()->environment(['local', 'testing'])
            && in_array(strtolower($parts['host'] ?? ''), ['127.0.0.1', 'localhost', '[::1]'], true);

        if (! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || (($parts['scheme'] ?? '') !== 'https' && ! $localHttp)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
        }

        return $url.'/password/callback';
    }
}
