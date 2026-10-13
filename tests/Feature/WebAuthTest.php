<?php

namespace Tests\Feature;

use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://auth-test.supabase.co/auth/v1';

    private const ID = '77777777-7777-4777-8777-777777777777';

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://auth-test.supabase.co', 'supabase.publishable_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_registration_requires_email_confirmation_and_cannot_self_assign_admin(): void
    {
        $this->post(route('register.store'), ['name' => 'An', 'email' => 'an@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin'])->assertSessionHasErrors('role');
        Http::assertNothingSent();
        Http::fake([self::URL.'/signup' => Http::response(['id' => self::ID, 'email' => 'an@example.test'])]);
        $this->post(route('register.store'), ['name' => 'An', 'email' => 'an@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'candidate'])->assertRedirect(route('login'))->assertSessionHas('success');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('candidate_profiles', 0);
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public function test_login_provisions_missing_profile_without_overwriting_existing_role(): void
    {
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($this->authSessionPayload())]);
        $this->post(route('login.store'), ['email' => 'an@example.test', 'password' => 'password123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(User::findOrFail(self::ID));
        $this->assertDatabaseHas('candidate_profiles', ['user_id' => self::ID]);
        $this->get(route('dashboard'))->assertRedirect(route('candidate.dashboard'));
        Http::assertSentCount(1);
    }

    public function test_repeated_obfuscated_signup_does_not_claim_email_or_block_later_verified_login(): void
    {
        Http::fake([
            self::URL.'/signup' => Http::sequence()
                ->push(['id' => '88888888-8888-4888-8888-888888888888', 'email' => 'an@example.test', 'identities' => []])
                ->push(['id' => '99999999-9999-4999-8999-999999999999', 'email' => 'an@example.test', 'identities' => []]),
            self::URL.'/token?grant_type=password' => Http::response($this->authSessionPayload()),
        ]);
        $payload = ['name' => 'Unverified name', 'email' => 'an@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'employer'];

        $this->post(route('register.store'), $payload)->assertRedirect(route('login'))->assertSessionHas('success');
        $this->post(route('register.store'), $payload)->assertRedirect(route('login'))->assertSessionHas('success');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('candidate_profiles', 0);
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => 'an@example.test', 'password' => 'password123'])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', ['id' => self::ID, 'email' => 'an@example.test', 'role' => 'candidate', 'name' => 'An']);
        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(3);
    }

    public function test_obfuscated_signup_cannot_modify_existing_local_account(): void
    {
        $existing = User::factory()->employer()->blocked()->create(['email' => 'an@example.test', 'name' => 'Existing name']);
        Http::fake([self::URL.'/signup' => Http::response(['id' => self::ID, 'email' => 'an@example.test', 'identities' => []])]);

        $this->post(route('register.store'), ['name' => 'Changed name', 'email' => 'an@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'candidate'])
            ->assertRedirect(route('login'))->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $existing->id, 'name' => 'Existing name', 'role' => 'employer', 'status' => 'BLOCKED']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('candidate_profiles', 0);
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public function test_failed_signup_has_no_local_side_effects(): void
    {
        Http::fake([self::URL.'/signup' => Http::response(['msg' => 'private diagnostic'], 503)]);

        $this->post(route('register.store'), ['name' => 'An', 'email' => 'an@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'candidate'])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('candidate_profiles', 0);
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    #[DataProvider('untrustedNames')]
    public function test_first_login_normalizes_untrusted_name_metadata(mixed $name, string $expected): void
    {
        $session = $this->authSessionPayload();
        $session['user']['user_metadata']['full_name'] = $name;
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($session)]);

        $this->post(route('login.store'), ['email' => 'an@example.test', 'password' => 'password123'])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', ['id' => self::ID, 'name' => $expected]);
        $this->assertDatabaseHas('candidate_profiles', ['user_id' => self::ID, 'full_name' => $expected]);
        Http::assertSentCount(1);
    }

    public static function untrustedNames(): array
    {
        return [[['unexpected' => 'array'], 'Thành viên'], ['   ', 'Thành viên'], [str_repeat('Á', 300), str_repeat('Á', 120)]];
    }

    public function test_existing_user_role_cannot_be_changed_by_mutable_metadata(): void
    {
        $user = User::factory()->candidate()->create(['id' => self::ID]);
        $session = $this->authSessionPayload();
        $session['user']['user_metadata']['account_type'] = 'admin';
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($session)]);
        $this->post(route('login.store'), ['email' => 'an@example.test', 'password' => 'password123'])->assertRedirect();
        $this->assertSame('candidate', $user->fresh()->role);
        Http::assertSentCount(1);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_login_discards_admin_destination_from_guest_visit(string $role): void
    {
        $user = User::factory()->create(['id' => self::ID, 'role' => $role]);
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($this->authSessionPayload())]);
        $this->get(route('admin.users'))->assertRedirect(route('admin.login'));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'))->assertSessionMissing('url.intended');

        $this->get(route('dashboard'))->assertRedirect(route($role.'.dashboard'));
    }

    public static function nonAdminRoles(): array
    {
        return ['candidate' => ['candidate'], 'employer' => ['employer']];
    }

    public function test_admin_login_preserves_requested_admin_destination(): void
    {
        $admin = User::factory()->admin()->create(['id' => self::ID]);
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($this->authSessionPayload())]);
        $this->get(route('admin.users'))->assertRedirect(route('admin.login'));

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect(route('admin.mfa'))->assertSessionHas('admin_password_confirmation.user_id', $admin->id)
            ->assertSessionMissing('admin_confirmation')->assertSessionHas('url.intended', route('admin.users'));

        $this->get(route('admin.users'))->assertRedirect(route('admin.mfa'));
    }

    public function test_candidate_login_preserves_requested_job_application_destination(): void
    {
        $candidate = User::factory()->candidate()->create(['id' => self::ID]);
        $job = JobPosting::factory()->accepting()->create();
        Http::fake([self::URL.'/token?grant_type=password' => Http::response($this->authSessionPayload())]);
        $this->get(route('candidate.apply', $job))->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => $candidate->email, 'password' => 'password123'])
            ->assertRedirect(route('candidate.apply', $job));
    }

    public function test_invalid_or_unconfirmed_login_returns_safe_error(): void
    {
        Http::fake([self::URL.'/token?grant_type=password' => Http::response(['error_code' => 'email_not_confirmed', 'msg' => 'remote private diagnostic'], 400)]);
        $this->post(route('login.store'), ['email' => 'an@example.test', 'password' => 'password123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public function test_logout_invalidates_local_session_and_revokes_remote_session(): void
    {
        $user = User::factory()->candidate()->create();
        Http::fake([self::URL.'/logout?scope=local' => Http::response(null, 204)]);
        $this->actingAs($user)->withSession(['supabase_session' => ['access_token' => 'test-token']])->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public function test_local_demo_is_disabled_in_production_and_from_non_loopback(): void
    {
        config(['app.demo_mode' => true]);
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'demo-csrf'])->post(route('demo.login', 'admin'), ['_token' => 'demo-csrf'])->assertNotFound();
        $this->app->instance('env', 'local');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->withSession(['_token' => 'demo-csrf'])->post(route('demo.login', 'admin'), ['_token' => 'demo-csrf'])->assertNotFound();
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_local_demo_only_uses_seeded_demo_accounts(): void
    {
        $this->app->instance('env', 'local');
        config(['app.demo_mode' => true]);
        $user = User::factory()->candidate()->create(['is_demo' => true]);
        $this->withSession(['_token' => 'demo-csrf'])->post(route('demo.login', 'candidate'), ['_token' => 'demo-csrf'])->assertRedirect(route('dashboard'))->assertSessionHas('demo_auth', true);
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();
    }

    public function test_web_session_refreshes_expired_token_and_checks_same_identity(): void
    {
        $user = User::factory()->candidate()->create(['id' => self::ID]);
        $this->app->instance('env', 'local');
        config(['app.demo_mode' => false]);
        Http::fake([self::URL.'/token?grant_type=refresh_token' => Http::response($this->authSessionPayload())]);
        $this->actingAs($user)->withSession(['supabase_session' => ['access_token' => 'old', 'refresh_token' => 'refresh-old', 'expires_at' => 1]])->get(route('dashboard'))->assertRedirect(route('candidate.dashboard'));
        Http::assertSentCount(1);
    }

    public function test_demo_can_select_another_active_seeded_candidate(): void
    {
        $this->app->instance('env', 'local');
        config(['app.demo_mode' => true]);
        User::factory()->candidate()->create(['is_demo' => true]);
        $selected = User::factory()->candidate()->create(['is_demo' => true]);
        $this->withSession(['_token' => 'demo-csrf'])->post(route('demo.login', 'candidate'), ['_token' => 'demo-csrf', 'user_id' => $selected->id])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($selected);
        Http::assertNothingSent();
    }

    public function test_demo_selector_cannot_sign_in_real_accounts_or_wrong_roles(): void
    {
        $this->app->instance('env', 'local');
        config(['app.demo_mode' => true]);
        $real = User::factory()->candidate()->create(['is_demo' => false]);
        $admin = User::factory()->create(['is_demo' => true, 'role' => 'admin']);
        foreach ([$real, $admin] as $account) {
            $this->withSession(['_token' => 'demo-csrf'])->post(route('demo.login', 'candidate'), ['_token' => 'demo-csrf', 'user_id' => $account->id])->assertNotFound();
            $this->assertGuest();
        }
        Http::assertNothingSent();
    }

    /** @return array<string,mixed> */
    private function authSessionPayload(): array
    {
        return ['user' => ['id' => self::ID, 'email' => 'an@example.test', 'user_metadata' => ['full_name' => 'An', 'account_type' => 'candidate']], 'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 3600, 'token_type' => 'bearer'];
    }
}
