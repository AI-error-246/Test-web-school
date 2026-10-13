<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryMfaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const AUTH = 'https://auth-test.supabase.co/auth/v1';

    private const FACTOR = 'a23ab549-20bb-4dc9-bbf1-d5bbf83bf366';

    private const CHALLENGE = 'b38b44fc-7608-420a-8f90-144fce1777b9';

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://auth-test.supabase.co', 'supabase.publishable_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_admin_recovery_completes_mfa_and_password_change_without_application_login(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $grant = $this->grant($admin);
        $upgraded = $this->providerSession($admin);
        Http::fake([
            self::AUTH.'/user' => fn (ClientRequest $request) => Http::response($request->method() === 'GET' ? $this->factorIdentity($admin) : ['id' => $admin->id]),
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response($upgraded),
            self::AUTH.'/logout?scope=global' => Http::response(null, 204),
        ]);
        $this->withSession(['password_recovery' => $grant]);

        $this->get(route('password.reset'))->assertOk()->assertSee('name="code"', false)
            ->assertDontSee('name="password"', false)->assertDontSee(self::FACTOR)->assertSessionHas('password_recovery.factor_id', self::FACTOR);
        $oldSession = session()->getId();
        $this->travel(2)->minutes();
        $this->post(route('password.mfa'), ['code' => '123456'])->assertRedirect(route('password.reset'))
            ->assertSessionHas('password_recovery.mfa_verified', true)->assertSessionHas('password_recovery.expires_at', $grant['expires_at'])
            ->assertSessionMissing('supabase_session')->assertSessionMissing('admin_confirmation')->assertSessionMissing('_old_input.code');
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertGuest();
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('password.reset'))->assertOk()->assertSee('name="password"', false)->assertDontSee('name="code"', false);
        $this->post(route('password.update'), $this->password())->assertRedirect(route('login'))->assertSessionMissing('password_recovery');

        $this->assertGuest();
        $this->assertSame(2, $admin->fresh()->auth_version);
        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'PUT' && $request->url() === self::AUTH.'/user'
            && $request->hasHeader('Authorization', 'Bearer '.$upgraded['access_token']) && $request->data() === ['password' => 'new-test-password']);
        Http::assertSentCount(8);
    }

    #[DataProvider('accountRoles')]
    public function test_direct_password_submission_cannot_bypass_existing_factor_for_any_role(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        Http::fake([self::AUTH.'/user' => Http::response($this->factorIdentity($user))]);

        $this->withSession(['password_recovery' => $this->grant($user)])->post(route('password.update'), [...$this->password(), 'mfa_verified' => true])
            ->assertSessionHasErrors('password')->assertSessionMissing('password_recovery.mfa_verified')->assertSessionMissing('_old_input.password');

        $this->assertSame(0, $user->fresh()->auth_version);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() === 'PUT');
    }

    public static function accountRoles(): array
    {
        return [['admin'], ['candidate'], ['employer']];
    }

    #[DataProvider('incompleteMfaStates')]
    public function test_mfa_marker_and_matching_aal2_token_are_both_required(bool $marker, string $aal): void
    {
        $user = User::factory()->admin()->create();
        $grant = [...$this->grant($user), 'mfa_verified' => $marker, 'access_token' => $this->token($user->id, $aal)];
        Http::fake([self::AUTH.'/user' => Http::response($this->factorIdentity($user))]);

        $this->withSession(['password_recovery' => $grant])->post(route('password.update'), $this->password())->assertSessionHasErrors('password');

        $this->assertSame(0, $user->fresh()->auth_version);
        Http::assertSentCount(1);
    }

    public static function incompleteMfaStates(): array
    {
        return [[false, 'aal2'], [true, 'aal1']];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_code_does_not_reach_provider_or_flash_otp(mixed $code): void
    {
        $user = User::factory()->admin()->create();

        $this->withSession(['password_recovery' => [...$this->grant($user), 'factor_id' => self::FACTOR]])
            ->post(route('password.mfa'), ['code' => $code])->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input.code')->assertSessionMissing('password_recovery.mfa_verified');

        Http::assertNothingSent();
    }

    public static function invalidCodes(): array
    {
        return [[null], ['12345'], ['12345x'], [['123456']]];
    }

    public function test_expired_recovery_grant_blocks_form_otp_and_password_write(): void
    {
        $this->freezeTime();
        $user = User::factory()->admin()->create();
        $grant = [...$this->grant($user), 'factor_id' => self::FACTOR, 'mfa_verified' => true];
        $this->withSession(['password_recovery' => $grant]);
        $this->travel(10)->minutes();

        $this->get(route('password.reset'))->assertRedirect(route('password.request'));
        $this->post(route('password.mfa'), ['code' => '123456'])->assertRedirect(route('password.request'));
        $this->post(route('password.update'), $this->password())->assertRedirect(route('password.request'));

        Http::assertNothingSent();
    }

    #[DataProvider('unusableVerifyResponses')]
    public function test_wrong_identity_or_aal1_verification_cannot_upgrade_recovery(string $failure): void
    {
        $user = User::factory()->admin()->create();
        $identity = $failure === 'wrong-user' ? User::factory()->admin()->create() : $user;
        $verified = $this->providerSession($identity, $failure === 'aal1' ? 'aal1' : 'aal2');
        Http::fake([
            self::AUTH.'/user' => Http::response($this->factorIdentity($user)),
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response($verified),
        ]);
        $grant = [...$this->grant($user), 'factor_id' => self::FACTOR];

        $this->withSession(['password_recovery' => $grant])->post(route('password.mfa'), ['code' => '123456'])
            ->assertSessionHasErrors('code')->assertSessionMissing('password_recovery.mfa_verified')
            ->assertSessionHas('password_recovery.access_token', $grant['access_token'])->assertSessionMissing('admin_confirmation');

        $this->assertGuest();
        Http::assertSentCount(3);
    }

    public static function unusableVerifyResponses(): array
    {
        return [['wrong-user'], ['aal1']];
    }

    #[DataProvider('verificationFailures')]
    public function test_rejected_otp_keeps_recovery_limited_without_leaking_provider_error(int $status): void
    {
        $user = User::factory()->admin()->create();
        Http::fake([
            self::AUTH.'/user' => Http::response($this->factorIdentity($user)),
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response(['message' => 'private-provider-error'], $status),
        ]);

        $this->withSession(['password_recovery' => [...$this->grant($user), 'factor_id' => self::FACTOR]])
            ->post(route('password.mfa'), ['code' => '654321'])->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input.code')->assertSessionMissing('password_recovery.mfa_verified');

        $this->assertStringNotContainsString('private-provider-error', session('errors')->first('code'));
        Http::assertSentCount(3);
    }

    public static function verificationFailures(): array
    {
        return [[400], [503]];
    }

    public function test_factor_discovery_outage_blocks_password_form_and_write_without_revoking_sessions(): void
    {
        $user = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::response(['message' => 'private-provider-error'], 503)]);
        $this->withSession(['password_recovery' => $this->grant($user)]);

        $this->get(route('password.reset'))->assertStatus(503)->assertDontSee('private-provider-error');
        $this->post(route('password.update'), $this->password())->assertSessionHasErrors('password');

        $this->assertSame(0, $user->fresh()->auth_version);
        Http::assertSentCount(2);
    }

    public function test_browser_selected_factor_is_ignored_without_server_setup(): void
    {
        $user = User::factory()->admin()->create();

        $this->withSession(['password_recovery' => $this->grant($user)])->post(route('password.mfa'), ['code' => '123456', 'factor_id' => self::FACTOR])
            ->assertRedirect(route('password.reset'))->assertSessionMissing('password_recovery.mfa_verified');

        Http::assertNothingSent();
    }

    public function test_removed_factor_returns_to_discovery_without_challenging_old_factor(): void
    {
        $user = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::response(['id' => $user->id, 'factors' => []])]);

        $this->withSession(['password_recovery' => [...$this->grant($user), 'factor_id' => self::FACTOR]])
            ->post(route('password.mfa'), ['code' => '123456'])->assertRedirect(route('password.reset'))
            ->assertSessionMissing('password_recovery.factor_id')->assertSessionMissing('password_recovery.mfa_verified');

        Http::assertSentCount(1);
    }

    public function test_factor_added_after_form_is_rechecked_before_password_change(): void
    {
        $user = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::sequence()->push(['id' => $user->id, 'factors' => []])->push($this->factorIdentity($user))]);
        $this->withSession(['password_recovery' => $this->grant($user)]);

        $this->get(route('password.reset'))->assertOk()->assertSee('name="password"', false);
        $this->post(route('password.update'), $this->password())->assertSessionHasErrors('password');

        $this->assertSame(0, $user->fresh()->auth_version);
        Http::assertSentCount(2);
    }

    public function test_verification_cannot_extend_recovery_past_shorter_provider_expiry(): void
    {
        $this->freezeTime();
        $user = User::factory()->admin()->create();
        $verified = $this->providerSession($user);
        $verified['expires_in'] = 45;
        $verified['access_token'] = $this->token($user->id, 'aal2', now()->addSeconds(45)->timestamp);
        Http::fake([
            self::AUTH.'/user' => Http::response($this->factorIdentity($user)),
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response($verified),
        ]);

        $this->withSession(['password_recovery' => [...$this->grant($user), 'factor_id' => self::FACTOR]])
            ->post(route('password.mfa'), ['code' => '123456'])->assertRedirect(route('password.reset'))
            ->assertSessionHas('password_recovery.expires_at', now()->addSeconds(45)->timestamp);
        $this->travel(45)->seconds();
        $this->post(route('password.update'), $this->password())->assertRedirect(route('password.request'));

        Http::assertSentCount(3);
    }

    public function test_otp_limiter_follows_recovery_user_across_ips(): void
    {
        $user = User::factory()->admin()->create();
        $this->withSession(['password_recovery' => $this->grant($user)]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->post(route('password.mfa'), ['code' => 'not-valid'])->assertSessionHasErrors('code');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])->post(route('password.mfa'), ['code' => '123456'])->assertStatus(429)->assertHeader('Retry-After');

        Http::assertNothingSent();
    }

    public function test_recovery_mfa_requires_csrf(): void
    {
        $user = User::factory()->admin()->create();
        $this->withSession(['password_recovery' => [...$this->grant($user), 'factor_id' => self::FACTOR]]);
        $this->app->instance('env', 'local');

        $this->post(route('password.mfa'), ['code' => '123456'])->assertStatus(419);

        Http::assertNothingSent();
    }

    private function grant(User $user): array
    {
        return ['user_id' => $user->id, 'access_token' => $this->token($user->id, 'aal1'), 'expires_at' => now()->addMinutes(10)->timestamp];
    }

    private function providerSession(User $user, string $aal = 'aal2'): array
    {
        return ['user' => ['id' => $user->id, 'email' => $user->email], 'access_token' => $this->token($user->id, $aal), 'refresh_token' => 'test-refresh', 'expires_in' => 3600];
    }

    private function token(string $id, string $aal, ?int $expires = null): string
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['sub' => $id, 'aal' => $aal, 'exp' => $expires ?? now()->addHour()->timestamp])), '+/', '-_'), '=');

        return 'eyJhbGciOiJIUzI1NiJ9.'.$claims.'.test-signature';
    }

    private function factorIdentity(User $user): array
    {
        return ['id' => $user->id, 'factors' => [['id' => self::FACTOR, 'factor_type' => 'totp', 'status' => 'verified']]];
    }

    private function challenge(): array
    {
        return ['id' => self::CHALLENGE, 'type' => 'totp', 'expires_at' => now()->addHour()->timestamp];
    }

    private function password(): array
    {
        return ['password' => 'new-test-password', 'password_confirmation' => 'new-test-password'];
    }
}
