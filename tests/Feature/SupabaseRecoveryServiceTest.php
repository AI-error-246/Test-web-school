<?php

namespace Tests\Feature;

use App\Exceptions\SupabaseRequestException;
use App\Services\SupabaseAuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupabaseRecoveryServiceTest extends TestCase
{
    private const URL = 'https://test-project.supabase.co/auth/v1';

    private const USER = ['id' => '550e8400-e29b-41d4-a716-446655440000', 'email' => 'student@example.test'];

    private const CODE = '6590f930-1ec2-429c-bf68-5c1a715730ba';

    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://test-project.supabase.co', 'supabase.publishable_key' => 'sb_publishable_test',
            'app.url' => 'http://127.0.0.1:3001']);
        Http::preventStrayRequests();
    }

    public function test_recovery_sends_s256_challenge_and_only_the_canonical_callback(): void
    {
        $endpoint = self::URL.'/recover?redirect_to=http%3A%2F%2F127.0.0.1%3A3001%2Fpassword%2Fcallback';
        Http::fake([$endpoint => Http::response([])]);

        app(SupabaseAuthService::class)->requestPasswordRecovery('student@example.test', self::CHALLENGE);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === $endpoint
            && $request->hasHeader('apikey', 'sb_publishable_test')
            && ! $request->hasHeader('Authorization')
            && $request->data() === ['email' => 'student@example.test', 'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 's256']);
        Http::assertSentCount(1);
    }

    public function test_recovery_accepts_https_canonical_origin_with_trailing_slash(): void
    {
        config(['app.url' => 'https://internship.example.test/']);
        $endpoint = self::URL.'/recover?redirect_to=https%3A%2F%2Finternship.example.test%2Fpassword%2Fcallback';
        Http::fake([$endpoint => Http::response([])]);

        app(SupabaseAuthService::class)->requestPasswordRecovery('student@example.test', self::CHALLENGE);

        Http::assertSent(fn (Request $request): bool => $request->url() === $endpoint);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_callback_configuration_fails_before_sending_email(string $origin): void
    {
        config(['app.url' => $origin]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->requestPasswordRecovery('student@example.test', self::CHALLENGE), 'AUTH_UNAVAILABLE', 503);

        Http::assertNothingSent();
    }

    public static function invalidOrigins(): array
    {
        return [
            'insecure remote origin' => ['http://internship.example.test'],
            'host spoof' => ['http://127.0.0.1.attacker.test:3001'],
            'credentials' => ['https://user:password@internship.example.test'],
            'query' => ['https://internship.example.test?redirect_to=https://attacker.test'],
            'fragment' => ['https://internship.example.test#redirect'],
            'unexpected base path' => ['https://internship.example.test/unexpected'],
            'protocol relative' => ['//internship.example.test'],
            'control characters' => ["https://internship.example.test\r\n"],
            'backslash' => ['https://internship.example.test\\@attacker.test'],
            'empty' => [''],
        ];
    }

    public function test_http_loopback_callback_is_rejected_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->requestPasswordRecovery('student@example.test', self::CHALLENGE), 'AUTH_UNAVAILABLE', 503);

        Http::assertNothingSent();
    }

    public function test_pkce_exchange_sends_verifier_in_body_and_returns_validated_session(): void
    {
        $this->travelTo(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        Http::fake([self::URL.'/token?grant_type=pkce' => Http::response($this->sessionPayload())]);

        $session = app(SupabaseAuthService::class)->exchangeRecoveryCode(self::CODE, self::VERIFIER);

        $this->assertSame(['user' => self::USER, 'access_token' => 'recovery-access', 'refresh_token' => 'recovery-refresh',
            'expires_in' => 3600, 'expires_at' => 1893459600, 'token_type' => 'bearer'], $session);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/token?grant_type=pkce'
            && $request->data() === ['auth_code' => self::CODE, 'code_verifier' => self::VERIFIER]
            && ! $request->hasHeader('Authorization'));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidProofs')]
    public function test_invalid_pkce_proof_fails_before_network(string $method, string $first, string $second): void
    {
        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->{$method}($first, $second), 'AUTH_FAILED', 401);

        Http::assertNothingSent();
    }

    public static function invalidProofs(): array
    {
        return [
            'empty challenge' => ['requestPasswordRecovery', 'student@example.test', ''],
            'plain challenge' => ['requestPasswordRecovery', 'student@example.test', 'plain'],
            'padded challenge' => ['requestPasswordRecovery', 'student@example.test', self::CHALLENGE.'='],
            'injected challenge' => ['requestPasswordRecovery', 'student@example.test', self::CHALLENGE."\n"],
            'invalid code' => ['exchangeRecoveryCode', 'not-a-uuid', self::VERIFIER],
            'empty verifier' => ['exchangeRecoveryCode', self::CODE, ''],
            'short verifier' => ['exchangeRecoveryCode', self::CODE, str_repeat('x', 42)],
            'long verifier' => ['exchangeRecoveryCode', self::CODE, str_repeat('x', 129)],
            'verifier whitespace' => ['exchangeRecoveryCode', self::CODE, self::VERIFIER."\n"],
        ];
    }

    public function test_password_update_sends_only_password_and_authenticates_as_recovery_user(): void
    {
        Http::fake([self::URL.'/user' => Http::response(self::USER)]);

        $user = app(SupabaseAuthService::class)->updateRecoveredPassword('recovery-access', 'example-new-password');

        $this->assertSame(self::USER, $user);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === self::URL.'/user'
            && $request->hasHeader('Authorization', 'Bearer recovery-access')
            && $request->hasHeader('apikey', 'sb_publishable_test')
            && $request->data() === ['password' => 'example-new-password']);
        Http::assertSentCount(1);
    }

    public function test_password_update_rejects_invalid_returned_identity(): void
    {
        Http::fake([self::URL.'/user' => Http::response(['id' => 'not-a-uuid'])]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->updateRecoveredPassword('recovery-access', 'example-new-password'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(1);
    }

    public function test_global_logout_revokes_all_remote_refresh_sessions(): void
    {
        Http::fake([self::URL.'/logout?scope=global' => Http::response('', 204)]);

        app(SupabaseAuthService::class)->signOutAll('recovery-access');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/logout?scope=global'
            && $request->hasHeader('Authorization', 'Bearer recovery-access')
            && $request->data() === []);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidTokenActions')]
    public function test_invalid_recovery_access_token_never_reaches_remote_endpoint(string $method): void
    {
        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->{$method}("token\r\ninjected", 'example-new-password'), 'AUTH_FAILED', 401);

        Http::assertNothingSent();
    }

    public static function invalidTokenActions(): array
    {
        return [['updateRecoveredPassword'], ['signOutAll']];
    }

    #[DataProvider('remoteFailures')]
    public function test_recovery_provider_failures_do_not_expose_remote_response(int $remoteStatus, string $error, int $status): void
    {
        $endpoint = self::URL.'/recover?redirect_to=http%3A%2F%2F127.0.0.1%3A3001%2Fpassword%2Fcallback';
        Http::fake([$endpoint => Http::response(['msg' => 'private-smtp-account-information'], $remoteStatus)]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->requestPasswordRecovery('student@example.test', self::CHALLENGE), $error, $status);

        Http::assertSentCount(1);
    }

    public static function remoteFailures(): array
    {
        return [[422, 'AUTH_FAILED', 401], [429, 'AUTH_RATE_LIMITED', 429], [500, 'AUTH_UNAVAILABLE', 503], [302, 'AUTH_UNAVAILABLE', 503]];
    }

    public function test_expired_or_replayed_code_is_sanitized(): void
    {
        Http::fake([self::URL.'/token?grant_type=pkce' => Http::response(['error_code' => 'flow_state_expired', 'msg' => 'private-code-details'], 400)]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->exchangeRecoveryCode(self::CODE, self::VERIFIER), 'AUTH_FAILED', 401);

        Http::assertSentCount(1);
    }

    public function test_exchange_rejects_incomplete_session_payload(): void
    {
        Http::fake([self::URL.'/token?grant_type=pkce' => Http::response(['user' => self::USER, 'access_token' => 'recovery-access'])]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->exchangeRecoveryCode(self::CODE, self::VERIFIER), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(1);
    }

    public function test_recovery_connection_failure_does_not_expose_network_details(): void
    {
        $endpoint = self::URL.'/recover?redirect_to=http%3A%2F%2F127.0.0.1%3A3001%2Fpassword%2Fcallback';
        Http::fake([$endpoint => Http::failedConnection('private-network-information')]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->requestPasswordRecovery('student@example.test', self::CHALLENGE), 'AUTH_UNAVAILABLE', 503);
    }

    private function sessionPayload(): array
    {
        return ['user' => self::USER, 'access_token' => 'recovery-access', 'refresh_token' => 'recovery-refresh', 'expires_in' => 3600];
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
