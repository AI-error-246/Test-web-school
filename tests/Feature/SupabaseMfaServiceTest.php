<?php

namespace Tests\Feature;

use App\Exceptions\SupabaseRequestException;
use App\Services\SupabaseAuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupabaseMfaServiceTest extends TestCase
{
    private const URL = 'https://test-project.supabase.co/auth/v1';

    private const USER = ['id' => '550e8400-e29b-41d4-a716-446655440000', 'email' => 'admin@example.test'];

    private const FACTOR = '6590f930-1ec2-429c-bf68-5c1a715730ba';

    private const CHALLENGE = 'c7a5b8c0-099b-4aa0-a8f1-c82a922c9b5a';

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://test-project.supabase.co', 'supabase.publishable_key' => 'sb_publishable_test']);
        Http::preventStrayRequests();
    }

    public function test_lists_only_verified_totp_factors_with_authenticated_user_endpoint(): void
    {
        Http::fake([self::URL.'/user' => Http::response(self::USER + ['factors' => [
            ['id' => self::FACTOR, 'factor_type' => 'totp', 'status' => 'verified', 'friendly_name' => 'My authenticator'],
            ['id' => self::CHALLENGE, 'factor_type' => 'totp', 'status' => 'unverified'],
            ['id' => self::USER['id'], 'factor_type' => 'phone', 'status' => 'verified'],
        ]])]);

        $factors = app(SupabaseAuthService::class)->mfaFactors('server-access');

        $this->assertSame([['id' => self::FACTOR, 'friendly_name' => 'My authenticator']], $factors);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::URL.'/user' && $request->hasHeader('Authorization', 'Bearer server-access'));
        Http::assertSentCount(1);
    }

    public function test_user_without_factors_can_begin_enrollment(): void
    {
        Http::fake([self::URL.'/user' => Http::response(self::USER)]);

        $this->assertSame([], app(SupabaseAuthService::class)->mfaFactors('server-access'));

        Http::assertSentCount(1);
    }

    #[DataProvider('invalidFactorLists')]
    public function test_malformed_factor_lists_fail_closed(mixed $factors): void
    {
        Http::fake([self::URL.'/user' => Http::response(self::USER + ['factors' => $factors])]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->mfaFactors('server-access'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(1);
    }

    public static function invalidFactorLists(): array
    {
        return [
            'not a list' => ['private-invalid-data'],
            'object instead of list' => [['unexpected' => true]],
            'missing UUID' => [[['factor_type' => 'totp', 'status' => 'verified']]],
            'missing status' => [[['id' => self::FACTOR, 'factor_type' => 'totp']]],
            'missing type' => [[['id' => self::FACTOR, 'status' => 'verified']]],
        ];
    }

    public function test_enrollment_returns_only_provisioning_details_from_provider(): void
    {
        Http::fake([self::URL.'/factors' => Http::response($this->enrollmentPayload())]);

        $factor = app(SupabaseAuthService::class)->enrollTotp('server-access');

        $this->assertSame(['id' => self::FACTOR, 'secret' => 'JBSWY3DPEHPK3PXP', 'qr_code' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>'], $factor);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/factors'
            && $request->hasHeader('Authorization', 'Bearer server-access')
            && $request->hasHeader('apikey', 'sb_publishable_test')
            && $request['factor_type'] === 'totp' && str_starts_with($request['friendly_name'], 'InternBridge Admin ')
            && $request['issuer'] === 'InternBridge');
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidEnrollments')]
    public function test_incomplete_enrollment_fails_without_exposing_provider_content(array $patch): void
    {
        Http::fake([self::URL.'/factors' => Http::response(array_replace_recursive($this->enrollmentPayload(), $patch))]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->enrollTotp('server-access'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(1);
    }

    public static function invalidEnrollments(): array
    {
        return [
            'invalid id' => [['id' => '../private-factor']],
            'wrong factor' => [['type' => 'phone']],
            'missing secret' => [['totp' => ['secret' => null]]],
            'invalid secret' => [['totp' => ['secret' => '<private-provider-value>']]],
            'missing QR' => [['totp' => ['qr_code' => '']]],
            'oversize QR' => [['totp' => ['qr_code' => str_repeat('x', 1024 * 1024 + 1)]]],
        ];
    }

    public function test_accepts_provider_svg_larger_than_the_legacy_enrollment_limit(): void
    {
        $opening = '<svg xmlns="http://www.w3.org/2000/svg"><desc>';
        $closing = '</desc></svg>';
        $svg = $opening.str_repeat('x', 321600 - strlen($opening.$closing)).$closing;
        $payload = $this->enrollmentPayload();
        $payload['totp']['qr_code'] = $svg;
        Http::fake([self::URL.'/factors' => Http::response($payload)]);

        $factor = app(SupabaseAuthService::class)->enrollTotp('server-access');

        $this->assertSame($svg, $factor['qr_code']);
        $this->assertSame(self::FACTOR, $factor['id']);
        Http::assertSentCount(1);
    }

    public function test_challenges_then_verifies_code_and_returns_elevated_rotated_credentials(): void
    {
        $this->freezeTime();
        $payload = $this->sessionPayload();
        Http::fake([
            self::URL.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challengePayload()),
            self::URL.'/factors/'.self::FACTOR.'/verify' => Http::response($payload),
        ]);

        $session = app(SupabaseAuthService::class)->verifyTotp('server-access', self::FACTOR, '012345');

        $this->assertSame(self::USER, $session['user']);
        $this->assertSame($payload['access_token'], $session['access_token']);
        $this->assertSame('rotated-refresh', $session['refresh_token']);
        $this->assertSame(now()->timestamp + 3600, $session['expires_at']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::URL.'/factors/'.self::FACTOR.'/challenge'
            && $request->method() === 'POST' && $request->body() === '{}'
            && $request->hasHeader('Authorization', 'Bearer server-access'));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::URL.'/factors/'.self::FACTOR.'/verify'
            && $request->method() === 'POST' && $request->data() === ['challenge_id' => self::CHALLENGE, 'code' => '012345']
            && $request->hasHeader('Authorization', 'Bearer server-access'));
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidProofs')]
    public function test_invalid_factor_or_code_is_rejected_before_network(string $factorId, string $code): void
    {
        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->verifyTotp('server-access', $factorId, $code), 'AUTH_FAILED', 401);

        Http::assertNothingSent();
    }

    public static function invalidProofs(): array
    {
        return [
            'path injection' => ['../user', '123456'],
            'short code' => [self::FACTOR, '12345'],
            'long code' => [self::FACTOR, '1234567'],
            'alphabetic code' => [self::FACTOR, 'ABCDEF'],
            'whitespace' => [self::FACTOR, "123456\n"],
            'non ASCII digits' => [self::FACTOR, '１２３４５６'],
        ];
    }

    #[DataProvider('invalidChallenges')]
    public function test_invalid_challenge_never_sends_otp_to_verify(array $patch): void
    {
        $this->freezeTime();
        Http::fake([self::URL.'/factors/'.self::FACTOR.'/challenge' => Http::response(array_replace($this->challengePayload(), $patch))]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->verifyTotp('server-access', self::FACTOR, '012345'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(1);
    }

    public static function invalidChallenges(): array
    {
        return [
            'missing UUID' => [['id' => null]],
            'wrong type' => [['type' => 'phone']],
            'expired challenge' => [['expires_at' => 1]],
            'invalid expiry type' => [['expires_at' => '9999999999']],
        ];
    }

    #[DataProvider('invalidAalClaims')]
    public function test_verify_rejects_aal_downgrade_wrong_identity_and_expired_credentials(array $claims): void
    {
        $this->freezeTime();
        $payload = $this->sessionPayload();
        $payload['access_token'] = $this->token(array_replace(['aal' => 'aal2', 'sub' => self::USER['id'], 'exp' => now()->timestamp + 3600], $claims));
        Http::fake([
            self::URL.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challengePayload()),
            self::URL.'/factors/'.self::FACTOR.'/verify' => Http::response($payload),
        ]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->verifyTotp('server-access', self::FACTOR, '012345'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(2);
    }

    public static function invalidAalClaims(): array
    {
        return [
            'password alone' => [['aal' => 'aal1']],
            'another identity' => [['sub' => self::FACTOR]],
            'expired access token' => [['exp' => 1]],
            'missing expiry' => [['exp' => null]],
        ];
    }

    public function test_verify_rejects_incomplete_session(): void
    {
        $this->freezeTime();
        Http::fake([
            self::URL.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challengePayload()),
            self::URL.'/factors/'.self::FACTOR.'/verify' => Http::response(['user' => self::USER]),
        ]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->verifyTotp('server-access', self::FACTOR, '012345'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(2);
    }

    public function test_stored_claim_check_rejects_malformed_and_unsigned_tokens(): void
    {
        $this->freezeTime();
        $claims = ['aal' => 'aal2', 'sub' => self::USER['id'], 'exp' => now()->timestamp + 3600];

        $this->assertFalse(SupabaseAuthService::isAal2('invalid', self::USER['id']));
        $this->assertFalse(SupabaseAuthService::isAal2($this->token($claims, 'none'), self::USER['id']));
        $this->assertFalse(SupabaseAuthService::isAal2($this->token($claims), 'not-a-uuid'));
        $this->assertTrue(SupabaseAuthService::isAal2($this->token($claims), self::USER['id']));
        Http::assertNothingSent();
    }

    #[DataProvider('remoteFailures')]
    public function test_invalid_or_rate_limited_otp_errors_are_sanitized(int $remoteStatus, string $error, int $status): void
    {
        $this->freezeTime();
        Http::fake([
            self::URL.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challengePayload()),
            self::URL.'/factors/'.self::FACTOR.'/verify' => Http::response(['msg' => 'private-provider-code-and-token'], $remoteStatus),
        ]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->verifyTotp('server-access', self::FACTOR, '012345'), $error, $status);

        Http::assertSentCount(2);
    }

    public static function remoteFailures(): array
    {
        return [[422, 'AUTH_FAILED', 401], [429, 'AUTH_RATE_LIMITED', 429], [500, 'AUTH_UNAVAILABLE', 503], [302, 'AUTH_UNAVAILABLE', 503]];
    }

    public function test_connection_failure_is_sanitized_before_otp_submission(): void
    {
        Http::fake([self::URL.'/factors/'.self::FACTOR.'/challenge' => Http::failedConnection('private-network-data')]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->verifyTotp('server-access', self::FACTOR, '012345'), 'AUTH_UNAVAILABLE', 503);
    }

    private function enrollmentPayload(): array
    {
        return ['id' => self::FACTOR, 'type' => 'totp', 'totp' => ['secret' => 'JBSWY3DPEHPK3PXP',
            'qr_code' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'uri' => 'otpauth://totp/InternBridge']];
    }

    private function challengePayload(): array
    {
        return ['id' => self::CHALLENGE, 'type' => 'totp', 'expires_at' => now()->timestamp + 300];
    }

    private function sessionPayload(): array
    {
        return ['user' => self::USER, 'access_token' => $this->token(['aal' => 'aal2', 'sub' => self::USER['id'], 'exp' => now()->timestamp + 3600]),
            'refresh_token' => 'rotated-refresh', 'expires_in' => 3600];
    }

    private function token(array $claims, string $algorithm = 'ES256'): string
    {
        $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => $algorithm, 'typ' => 'JWT']).'.'.$encode($claims).'.test-signature';
    }

    private function assertAuthError(callable $action, string $error, int $status): void
    {
        try {
            $action();
            $this->fail('Expected a sanitized SupabaseRequestException.');
        } catch (SupabaseRequestException $exception) {
            $this->assertSame($error, $exception->getMessage());
            $this->assertSame($status, $exception->status);
            $this->assertNull($exception->getPrevious());
        }
    }
}
