<?php

namespace Tests\Feature;

use App\Exceptions\SupabaseRequestException;
use App\Services\SupabaseAuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupabaseAuthServiceTest extends TestCase
{
    private const URL = 'https://test-project.supabase.co/auth/v1';

    private const USER = ['id' => '550e8400-e29b-41d4-a716-446655440000', 'email' => 'student@example.test'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://test-project.supabase.co', 'supabase.publishable_key' => 'sb_publishable_test']);
        Http::preventStrayRequests();
    }

    public function test_signin_returns_session_and_sends_password_only_to_configured_endpoint(): void
    {
        $this->travelTo(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($this->sessionPayload())]);

        $result = app(SupabaseAuthService::class)->signIn('student@example.test', 'example-password');

        $this->assertSame(['user' => self::USER, 'access_token' => 'access-example', 'refresh_token' => 'refresh-example',
            'expires_in' => 3600, 'expires_at' => 1893459600, 'token_type' => 'bearer'], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/token?grant_type=password'
            && $request->hasHeader('apikey', 'sb_publishable_test')
            && ! $request->hasHeader('Authorization')
            && $request->data() === ['email' => 'student@example.test', 'password' => 'example-password']);
        Http::assertSentCount(1);
    }

    public function test_signup_returns_user_without_session_when_email_confirmation_is_required(): void
    {
        Http::fake([self::URL.'/signup' => Http::response(self::USER)]);

        $result = app(SupabaseAuthService::class)->signup('student@example.test', 'example-password', [
            'full_name' => 'Sinh viên thử nghiệm', 'account_type' => 'candidate', 'role' => 'admin', 'app_metadata' => ['role' => 'admin'],
        ]);

        $this->assertSame(['user' => self::USER, 'access_token' => null, 'refresh_token' => null, 'expires_in' => null, 'expires_at' => null, 'token_type' => null], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/signup'
            && (array) $request['data'] === ['full_name' => 'Sinh viên thử nghiệm', 'account_type' => 'candidate']
            && $request['email'] === 'student@example.test' && $request['password'] === 'example-password');
        Http::assertSentCount(1);
    }

    public function test_signup_with_session_does_not_accept_admin_account_type(): void
    {
        $this->freezeTime();
        Http::fake([self::URL.'/signup' => Http::response($this->sessionPayload())]);

        $result = app(SupabaseAuthService::class)->signup('student@example.test', 'example-password', ['account_type' => 'admin']);

        $this->assertSame('access-example', $result['access_token']);
        Http::assertSent(fn (Request $request): bool => (array) $request['data'] === []);
    }

    public function test_refresh_returns_rotated_credentials(): void
    {
        $this->freezeTime();
        Http::fake([self::URL.'/token?grant_type=refresh_token' => Http::response($this->sessionPayload())]);

        $result = app(SupabaseAuthService::class)->refresh('old-refresh-token');

        $this->assertSame('refresh-example', $result['refresh_token']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/token?grant_type=refresh_token'
            && $request->data() === ['refresh_token' => 'old-refresh-token']);
        Http::assertSentCount(1);
    }

    public function test_logout_revokes_only_the_current_session(): void
    {
        Http::fake([self::URL.'/logout?scope=local' => Http::response('', 204)]);

        app(SupabaseAuthService::class)->signOut('access-example');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/logout?scope=local'
            && $request->hasHeader('Authorization', 'Bearer access-example')
            && $request->hasHeader('apikey', 'sb_publishable_test'));
        Http::assertSentCount(1);
    }

    public function test_user_returns_identity_from_authenticated_remote_endpoint(): void
    {
        Http::fake([self::URL.'/user' => Http::response(self::USER)]);

        $result = app(SupabaseAuthService::class)->user('access-example');

        $this->assertSame(self::USER, $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::URL.'/user'
            && $request->hasHeader('Authorization', 'Bearer access-example'));
        Http::assertSentCount(1);
    }

    #[DataProvider('remoteErrors')]
    public function test_signin_sanitizes_remote_errors(int $remoteStatus, array $body, string $error, int $status): void
    {
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($body, $remoteStatus)]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->signIn('student@example.test', 'example-password'), $error, $status);

        Http::assertSentCount(1);
    }

    public static function remoteErrors(): array
    {
        return [
            'credentials' => [400, ['error_code' => 'invalid_credentials', 'msg' => 'private remote details'], 'AUTH_FAILED', 401],
            'email confirmation' => [400, ['error_code' => 'email_not_confirmed'], 'EMAIL_NOT_CONFIRMED', 403],
            'rate limit' => [429, ['msg' => 'private details'], 'AUTH_RATE_LIMITED', 429],
            'server outage' => [500, ['msg' => 'private details'], 'AUTH_UNAVAILABLE', 503],
            'redirect' => [302, [], 'AUTH_UNAVAILABLE', 503],
        ];
    }

    public function test_signup_failure_uses_generic_error_without_account_enumeration(): void
    {
        Http::fake([self::URL.'/signup' => Http::response(['msg' => 'User already registered'], 422)]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->signup('student@example.test', 'example-password'), 'SIGNUP_FAILED', 422);

        Http::assertSentCount(1);
    }

    public function test_connection_failure_does_not_expose_connection_details(): void
    {
        Http::fake([self::URL.'/token?grant_type=password' => Http::failedConnection('secret-network-details')]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->signIn('student@example.test', 'example-password'), 'AUTH_UNAVAILABLE', 503);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected_without_sending_credentials(string $url, ?string $key): void
    {
        config(['supabase.url' => $url, 'supabase.publishable_key' => $key]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->signIn('student@example.test', 'example-password'), 'AUTH_UNAVAILABLE', 503);

        Http::assertNothingSent();
    }

    public static function invalidConfigurations(): array
    {
        return [
            ['http://test-project.supabase.co', 'public-test'],
            ['https://name:password@test-project.supabase.co', 'public-test'],
            ['https://test-project.supabase.co/other', 'public-test'],
            ['https://test-project.supabase.co?query=value', 'public-test'],
            ['https://test-project.supabase.co', null],
            ['https://test-project.supabase.co', "invalid\r\nheader"],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_success_payload_fails_closed(mixed $body): void
    {
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($body)]);

        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->signIn('student@example.test', 'example-password'), 'AUTH_UNAVAILABLE', 503);

        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        return [['not-json'], [['user' => self::USER]], [['user' => ['id' => 'invalid'], 'access_token' => 'x', 'refresh_token' => 'y', 'expires_in' => 3600]]];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_is_rejected_before_request(string $token): void
    {
        $this->assertAuthError(fn () => app(SupabaseAuthService::class)->refresh($token), 'AUTH_FAILED', 401);

        Http::assertNothingSent();
    }

    public static function invalidTokens(): array
    {
        return [[''], ["token\r\ninjected"], [str_repeat('x', 16385)]];
    }

    /** @return array<string, mixed> */
    private function sessionPayload(): array
    {
        return ['user' => self::USER, 'access_token' => 'access-example', 'refresh_token' => 'refresh-example', 'expires_in' => 3600, 'token_type' => 'bearer'];
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
