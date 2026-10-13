<?php

namespace Tests\Feature;

use App\Exceptions\SupabaseRequestException;
use App\Models\User;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CODE = '9c40b5ba-3a7c-4df0-bbc9-d7c8778b7204';

    public function test_request_sends_pkce_challenge_keeps_verifier_private_and_has_same_result_for_unknown_email(): void
    {
        $challenge = null;
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock) use (&$challenge): void {
            $mock->shouldReceive('requestPasswordRecovery')->once()->withArgs(function ($email, $value) use (&$challenge): bool {
                $challenge = $value;

                return $email === 'human@example.test';
            });
        });
        $response = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'Human@example.test']);
        $response->assertRedirect('/forgot-password')->assertSessionHas('success');
        $message = session('success');
        $verifier = session('password_recovery_request.verifier');
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $challenge);
        $this->get('/forgot-password')->assertDontSee($verifier, false)->assertSee('Gửi liên kết khôi phục');
        $this->mock(SupabaseAuthService::class, fn (MockInterface $mock) => $mock->shouldReceive('requestPasswordRecovery')->once()->andThrow(new SupabaseRequestException('AUTH_FAILED', 401)));
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('success', $message);
        $this->assertGuest();
    }

    #[DataProvider('providerRequestFailures')]
    public function test_resend_failures_keep_existing_link_and_do_not_disclose_account_status(string $error): void
    {
        $this->mock(SupabaseAuthService::class, fn (MockInterface $mock) => $mock->shouldReceive('requestPasswordRecovery')->once()->andThrow(new SupabaseRequestException($error, 503)));
        $this->withSession(['password_recovery' => ['access_token' => 'old'], 'password_recovery_request' => ['verifier' => 'previous']])->post('/forgot-password', ['email' => 'human@example.test'])
            ->assertSessionHasNoErrors()->assertSessionHas('success')->assertSessionHas('password_recovery.access_token', 'old')->assertSessionHas('password_recovery_request.verifier', 'previous');
    }

    public static function providerRequestFailures(): array
    {
        return [['AUTH_UNAVAILABLE'], ['AUTH_RATE_LIMITED'], ['AUTH_FAILED']];
    }

    public function test_honeypot_and_invalid_email_do_not_call_auth(): void
    {
        $this->mock(SupabaseAuthService::class)->shouldNotReceive('requestPasswordRecovery');
        $this->post('/forgot-password', ['email' => 'bad'])->assertSessionHasErrors('email');
        $this->post('/forgot-password', ['email' => 'human@example.test', 'contact_website' => 'spam'])->assertSessionHasErrors('contact_website');
    }

    public function test_mail_limiter_follows_email_across_ips_and_recovers_after_window(): void
    {
        $this->freezeTime();
        $this->mock(SupabaseAuthService::class, fn (MockInterface $mock) => $mock->shouldReceive('requestPasswordRecovery')->times(4));
        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->post('/forgot-password', ['email' => 'human@example.test'])->assertRedirect();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->post('/forgot-password', ['email' => 'HUMAN@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(16)->minutes();
        $this->post('/forgot-password', ['email' => 'human@example.test'])->assertRedirect();
    }

    public function test_callback_creates_only_a_short_lived_recovery_grant_and_cannot_be_reused(): void
    {
        $this->freezeTime();
        $user = User::factory()->candidate()->create();
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('exchangeRecoveryCode')->once()->with(self::CODE, str_repeat('a', 64))->andReturn($this->identity($user));
            $mock->shouldReceive('mfaFactors')->once()->with('test-access-token')->andReturn([]);
        });
        $this->withSession(['password_recovery_request' => $this->pending($user)])->get('/password/callback?code='.self::CODE)
            ->assertRedirect(route('password.reset'))->assertSessionMissing('supabase_session')->assertSessionMissing('password_recovery_request');
        $this->assertGuest();
        $this->assertSame(now()->addMinutes(10)->timestamp, session('password_recovery.expires_at'));
        $this->get('/reset-password')->assertOk()->assertDontSee('test-access-token');
        $this->get('/candidate')->assertRedirect(route('login'));
        $this->get('/password/callback?code='.self::CODE)->assertRedirect(route('password.request'))->assertSessionHas('password_recovery');
    }

    public function test_callback_rejects_wrong_email_without_logging_in(): void
    {
        $user = User::factory()->create();
        $session = $this->identity($user);
        $session['user']['email'] = 'different@example.test';
        $this->mock(SupabaseAuthService::class, fn (MockInterface $mock) => $mock->shouldReceive('exchangeRecoveryCode')->once()->andReturn($session));
        $this->withSession(['password_recovery_request' => $this->pending($user)])->get('/password/callback?code='.self::CODE)
            ->assertRedirect(route('password.request'))->assertSessionMissing('password_recovery');
        $this->assertGuest();
    }

    public function test_expired_or_missing_browser_request_stops_before_code_exchange(): void
    {
        $this->mock(SupabaseAuthService::class)->shouldNotReceive('exchangeRecoveryCode');
        $this->get('/password/callback?code='.self::CODE)->assertRedirect(route('password.request'));
        $this->withSession(['password_recovery_request' => ['expires_at' => now()->subSecond()->timestamp]])->get('/password/callback?code='.self::CODE)->assertRedirect(route('password.request'));
    }

    public function test_reset_requires_a_valid_grant_not_just_an_authenticated_session(): void
    {
        $this->mock(SupabaseAuthService::class)->shouldNotReceive('updateRecoveredPassword');
        $this->actingAs(User::factory()->candidate()->create())->post('/reset-password', $this->password())->assertRedirect(route('password.request'));
        $this->withSession(['password_recovery' => ['expires_at' => now()->subSecond()->timestamp]])->post('/reset-password', $this->password())->assertRedirect(route('password.request'));
    }

    public function test_successful_reset_revokes_old_sessions_and_clears_tokens_without_logging_in(): void
    {
        $user = User::factory()->candidate()->create();
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('mfaFactors')->once()->with('test-access-token')->andReturn([]);
            $mock->shouldReceive('updateRecoveredPassword')->once()->with('test-access-token', 'a-long-new-password')->andReturn(['id' => $user->id]);
            $mock->shouldReceive('signOutAll')->once()->with('test-access-token');
        });
        $this->withSession(['password_recovery' => $this->grant($user)])->post('/reset-password', $this->password())
            ->assertRedirect(route('login'))->assertSessionMissing('password_recovery')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 0])->get('/candidate')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_provider_timeout_still_revokes_old_sessions_and_allows_recovery_retry(): void
    {
        $user = User::factory()->create();
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('mfaFactors')->once()->with('test-access-token')->andReturn([]);
            $mock->shouldReceive('updateRecoveredPassword')->once()->andThrow(new SupabaseRequestException('AUTH_UNAVAILABLE', 503));
        });
        $this->withSession(['password_recovery' => $this->grant($user)])->post('/reset-password', $this->password())->assertSessionHasErrors('password')->assertSessionHas('password_recovery')->assertSessionMissing('_old_input.password');
        $this->assertSame(2, $user->fresh()->auth_version);
    }

    public function test_global_logout_outage_does_not_undo_a_successful_password_change(): void
    {
        $user = User::factory()->create();
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('mfaFactors')->once()->with('test-access-token')->andReturn([]);
            $mock->shouldReceive('updateRecoveredPassword')->once()->andReturn(['id' => $user->id]);
            $mock->shouldReceive('signOutAll')->once()->andThrow(new SupabaseRequestException('AUTH_UNAVAILABLE', 503));
        });
        $this->withSession(['password_recovery' => $this->grant($user)])->post('/reset-password', $this->password())->assertRedirect(route('login'))->assertSessionMissing('password_recovery');
        $this->assertSame(2, $user->fresh()->auth_version);
    }

    public function test_password_confirmation_and_length_are_checked_before_provider_write(): void
    {
        $user = User::factory()->create();
        $this->mock(SupabaseAuthService::class)->shouldNotReceive('updateRecoveredPassword');
        $this->withSession(['password_recovery' => $this->grant($user)])->post('/reset-password', ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['password' => 'a-long-new-password', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertSame(0, $user->fresh()->auth_version);
    }

    #[DataProvider('loginPaths')]
    public function test_login_cannot_finish_with_credentials_verified_across_a_reset(string $path): void
    {
        $user = User::factory()->admin()->create();
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('signIn')->once()->andReturnUsing(function () use ($user): array {
                User::whereKey($user->id)->increment('auth_version');

                return $this->identity($user);
            });
        });
        $this->post($path, ['email' => $user->email, 'password' => 'old-password'])->assertSessionHasErrors('email')->assertSessionMissing('supabase_session');
        $this->assertGuest();
    }

    public static function loginPaths(): array
    {
        return [['/login'], ['/admin/login']];
    }

    public function test_login_during_remote_update_is_revoked_even_when_provider_times_out(): void
    {
        $user = User::factory()->candidate()->create();
        $observedVersion = null;
        $this->mock(SupabaseAuthService::class, function (MockInterface $mock) use ($user, &$observedVersion): void {
            $mock->shouldReceive('mfaFactors')->once()->with('test-access-token')->andReturn([]);
            $mock->shouldReceive('updateRecoveredPassword')->once()->andReturnUsing(function () use ($user, &$observedVersion): never {
                $observedVersion = $user->fresh()->auth_version;
                throw new SupabaseRequestException('AUTH_UNAVAILABLE', 503);
            });
        });
        $this->withSession(['password_recovery' => $this->grant($user)])->post('/reset-password', $this->password())->assertSessionHasErrors('password');
        $this->assertNotSame($observedVersion, $user->fresh()->auth_version);
        $this->actingAs($user->fresh())->withSession(['auth_version' => $observedVersion])->get('/candidate')->assertRedirect(route('login'));
    }

    public function test_first_recovery_provisions_only_allowed_role_without_signing_in(): void
    {
        $user = User::factory()->candidate()->make();
        $session = $this->identity($user);
        $session['user']['user_metadata'] = ['account_type' => 'candidate', 'full_name' => 'Người thử', 'role' => 'admin'];
        $this->mock(SupabaseAuthService::class, fn (MockInterface $mock) => $mock->shouldReceive('exchangeRecoveryCode')->once()->andReturn($session));
        $this->withSession(['password_recovery_request' => $this->pending($user)])->get('/password/callback?code='.self::CODE)->assertRedirect(route('password.reset'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'candidate', 'auth_version' => 0]);
        $this->assertDatabaseHas('candidate_profiles', ['user_id' => $user->id]);
        $this->assertGuest();
    }

    public function test_first_recovery_never_provisions_an_admin_from_metadata(): void
    {
        $user = User::factory()->admin()->make();
        $session = $this->identity($user);
        $session['user']['user_metadata'] = ['account_type' => 'admin'];
        $this->mock(SupabaseAuthService::class, fn (MockInterface $mock) => $mock->shouldReceive('exchangeRecoveryCode')->once()->andReturn($session));
        $this->withSession(['password_recovery_request' => $this->pending($user)])->get('/password/callback?code='.self::CODE)->assertRedirect(route('password.request'))->assertSessionMissing('password_recovery');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_callback_does_not_consume_a_valid_pending_request(): void
    {
        $user = User::factory()->create();
        $this->mock(SupabaseAuthService::class)->shouldNotReceive('exchangeRecoveryCode');
        $this->withSession(['password_recovery_request' => $this->pending($user)])->get('/password/callback')->assertRedirect(route('password.request'))->assertHeader('Referrer-Policy', 'no-referrer')->assertSessionHas('password_recovery_request.verifier', str_repeat('a', 64));
    }

    private function pending(User $user): array
    {
        return ['email' => strtolower($user->email), 'verifier' => str_repeat('a', 64), 'expires_at' => now()->addHour()->timestamp];
    }

    private function grant(User $user): array
    {
        return ['user_id' => $user->id, 'access_token' => 'test-access-token', 'expires_at' => now()->addMinutes(10)->timestamp];
    }

    private function identity(User $user): array
    {
        return ['user' => ['id' => $user->id, 'email' => $user->email], 'access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token', 'expires_at' => now()->addHour()->timestamp];
    }

    private function password(): array
    {
        return ['password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'];
    }
}
