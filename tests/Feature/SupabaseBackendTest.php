<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupabaseBackendTest extends TestCase
{
    private const URL = 'https://test-project.supabase.co';

    private const JWKS = self::URL.'/auth/v1/.well-known/jwks.json';

    private const USER = '550e8400-e29b-41d4-a716-446655440000';

    private const NOW = 1893456000;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => self::URL, 'supabase.jwks_url' => null, 'cache.default' => 'array', 'app.debug' => false]);
        Http::preventStrayRequests();
        Cache::flush();
        JWT::$timestamp = self::NOW;
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        parent::tearDown();
    }

    public function test_health_is_local_and_preserves_contract(): void
    {
        $this->get('/health')->assertOk()->assertExactJson(['status' => 'ok', 'service' => 'internship-backend'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
    }

    #[DataProvider('invalidHeaders')]
    public function test_missing_or_malformed_authorization_is_rejected(?string $header): void
    {
        $this->getJson('/api/me', $header === null ? ['apikey' => 'sb_publishable_test'] : ['Authorization' => $header])
            ->assertUnauthorized()->assertExactJson(['error' => 'UNAUTHORIZED']);
        Http::assertNothingSent();
    }

    public static function invalidHeaders(): array
    {
        return [[null], [''], ['Basic abc'], ['Bearer abc'], ['Bearer a.b.c extra']];
    }

    #[DataProvider('algorithms')]
    public function test_valid_user_token_returns_only_identity(string $algorithm): void
    {
        [$token, $jwk] = $this->signedToken([], $algorithm);
        Http::fake([self::JWKS => Http::response(['keys' => [$jwk]])]);
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertExactJson([
            'user' => ['id' => self::USER, 'email' => 'student@example.test'],
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withToken($token)->getJson('/api/me')->assertOk();
        Http::assertSentCount(1);
    }

    public static function algorithms(): array
    {
        return [['RS256'], ['ES256']];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_signed_claims_are_rejected(array $claims): void
    {
        [$token, $jwk] = $this->signedToken($claims);
        Http::fake([self::JWKS => Http::response(['keys' => [$jwk]])]);
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized()->assertExactJson(['error' => 'INVALID_TOKEN']);
    }

    public static function invalidClaims(): array
    {
        return [
            'expired' => [['exp' => self::NOW - 1]],
            'future' => [['nbf' => self::NOW + 60]],
            'wrong issuer' => [['iss' => 'https://attacker.example/auth/v1']],
            'wrong audience' => [['aud' => 'service_role']],
            'missing expiry' => [['exp' => null]],
            'invalid subject' => [['sub' => 'not-a-uuid']],
            'missing subject' => [['sub' => null]],
        ];
    }

    public function test_service_role_cannot_impersonate_an_end_user(): void
    {
        [$token, $jwk] = $this->signedToken(['role' => 'service_role']);
        Http::fake([self::JWKS => Http::response(['keys' => [$jwk]])]);
        $this->withToken($token)->getJson('/api/me')->assertForbidden()->assertExactJson(['error' => 'USER_REQUIRED']);
    }

    public function test_forged_signature_is_rejected(): void
    {
        [$token] = $this->signedToken();
        [, $otherKey] = $this->signedToken();
        Http::fake([self::JWKS => Http::response(['keys' => [$otherKey]])]);
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_symmetric_tokens_are_rejected_without_network(): void
    {
        $token = JWT::encode(['sub' => self::USER], str_repeat('x', 32), 'HS256', 'test-key');
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_jwks_outage_is_reported_without_leaking_remote_details(): void
    {
        [$token] = $this->signedToken();
        Http::fake([self::JWKS => Http::response('private remote error', 503)]);
        $this->withToken($token)->getJson('/api/me')->assertStatus(503)->assertExactJson(['error' => 'AUTH_UNAVAILABLE']);
    }

    public function test_rotated_signing_key_is_refetched(): void
    {
        [$oldToken, $oldKey] = $this->signedToken();
        [$newToken, $newKey] = $this->signedToken([], 'RS256', 'rotated');
        Http::fake([self::JWKS => Http::sequence()->push(['keys' => [$oldKey]])->push(['keys' => [$newKey]])]);
        $this->withToken($oldToken)->getJson('/api/me')->assertOk();
        $this->withToken($newToken)->getJson('/api/me')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_untrusted_key_url_does_not_control_outbound_requests(): void
    {
        [$token, $jwk] = $this->signedToken([], 'RS256', 'test-key', ['jku' => 'https://attacker.example/keys']);
        Http::fake([self::JWKS => Http::response(['keys' => [$jwk]])]);
        $this->withToken($token)->getJson('/api/me')->assertOk();
        Http::assertSent(fn ($request): bool => $request->url() === self::JWKS);
        Http::assertSentCount(1);
    }

    public function test_misconfigured_key_url_fails_closed(): void
    {
        config(['supabase.jwks_url' => 'https://other.example/keys']);
        [$token] = $this->signedToken();
        $this->withToken($token)->getJson('/api/me')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_unknown_routes_and_wrong_methods_return_json(): void
    {
        $this->get('/unknown')->assertNotFound()->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/me')->assertStatus(405);
        $this->postJson('/health')->assertStatus(405);
    }

    /** @return array{string, array<string, mixed>} */
    private function signedToken(array $overrides = [], string $algorithm = 'RS256', string $kid = 'test-key', array $headers = []): array
    {
        $options = $algorithm === 'ES256'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $jwk = $algorithm === 'ES256'
            ? ['kty' => 'EC', 'crv' => 'P-256', 'x' => JWT::urlsafeB64Encode($details['ec']['x']), 'y' => JWT::urlsafeB64Encode($details['ec']['y'])]
            : ['kty' => 'RSA', 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
        $jwk += ['kid' => $kid, 'alg' => $algorithm, 'use' => 'sig'];
        $claims = array_replace(['iss' => self::URL.'/auth/v1', 'aud' => 'authenticated', 'sub' => self::USER,
            'exp' => self::NOW + 300, 'iat' => self::NOW - 10, 'role' => 'authenticated', 'email' => 'student@example.test'], $overrides);

        return [JWT::encode($claims, $key, $algorithm, $kid, $headers), $jwk];
    }
}
