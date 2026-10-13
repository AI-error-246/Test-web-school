<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://auth-test.supabase.co', 'supabase.publishable_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_guest_admin_routes_require_dedicated_login(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.login'))->assertOk()->assertSee('autocomplete="current-password"', false);
        $this->assertGuest();
    }

    public function test_admin_password_login_rotates_session_and_requires_mfa(): void
    {
        $admin = User::factory()->admin()->create();
        $this->fakeIdentity($admin);
        $this->withSession(['sentinel' => true]);
        $oldId = session()->getId();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'safe-test-password'])
            ->assertRedirect(route('admin.mfa'))->assertSessionHas('admin_password_confirmation.user_id', $admin->id)
            ->assertSessionMissing('admin_confirmation')
            ->assertSessionMissing('demo_auth')->assertSessionMissing('_old_input.password');

        $this->assertNotSame($oldId, session()->getId());
        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa'));
        $this->get(route('admin.login'))->assertOk();
        $this->travel(30)->minutes();
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertAuthenticatedAs($admin);
        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'safe-test-password'])->assertRedirect(route('admin.mfa'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa'));
    }

    public function test_ordinary_admin_password_login_also_requires_mfa(): void
    {
        $admin = User::factory()->admin()->create();
        $this->fakeIdentity($admin);

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'safe-test-password'])
            ->assertRedirect(route('admin.mfa'))->assertSessionHas('admin_password_confirmation.user_id', $admin->id)
            ->assertSessionMissing('admin_confirmation');

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa'));
    }

    #[DataProvider('deniedAccounts')]
    public function test_password_success_does_not_grant_admin_to_unprivileged_or_blocked_account(string $role, string $status): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $this->fakeIdentity($user);

        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => 'safe-test-password'])
            ->assertSessionHasErrors('email')->assertSessionMissing('admin_confirmation');

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => $role, 'status' => $status]);
    }

    public static function deniedAccounts(): array
    {
        return ['candidate' => ['candidate', 'ACTIVE'], 'employer' => ['employer', 'ACTIVE'], 'blocked admin' => ['admin', 'BLOCKED']];
    }

    public function test_wrong_password_never_preserves_old_confirmation_or_exposes_password(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake(['*/token?grant_type=password' => Http::response(['error_code' => 'invalid_credentials', 'msg' => 'private diagnostic'], 400)]);

        $this->actingAs($admin)->withSession(['admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp]])
            ->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'wrong-secret'])
            ->assertSessionHasErrors('email')->assertSessionMissing('admin_confirmation')->assertSessionMissing('_old_input.password');

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_admin_metadata_cannot_provision_admin_without_existing_backend_role(): void
    {
        $unprovisioned = User::factory()->admin()->make();
        $this->fakeIdentity($unprovisioned);

        $this->post(route('admin.login.store'), ['email' => $unprovisioned->email, 'password' => 'safe-test-password'])
            ->assertSessionHasErrors('email')->assertSessionMissing('admin_confirmation');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_blocked_admin_loses_confirmed_access_and_session(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp]]);
        $admin->update(['status' => 'BLOCKED']);

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'))->assertSessionMissing('admin_confirmation');

        $this->assertGuest();
    }

    public function test_identity_bound_marker_cannot_be_reused_by_another_admin_or_demo(): void
    {
        $admin = User::factory()->admin()->create(['is_demo' => true]);
        $other = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['admin_confirmation' => ['user_id' => $other->id, 'confirmed_at' => now()->timestamp]])
            ->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->app->instance('env', 'local');
        config(['app.demo_mode' => true]);
        $this->withSession(['demo_auth' => true, 'admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp]])
            ->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_demo_admin_login_is_unavailable_even_locally(): void
    {
        User::factory()->admin()->create(['is_demo' => true]);
        $this->app->instance('env', 'local');
        config(['app.demo_mode' => true]);

        $this->withSession(['_token' => 'csrf-value'])->post(route('demo.login', 'admin'), ['_token' => 'csrf-value'])->assertNotFound();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_existing_candidate_cannot_enter_admin_login_or_use_an_admin_marker(): void
    {
        $candidate = User::factory()->candidate()->create();
        $this->actingAs($candidate)->withSession(['admin_confirmation' => ['user_id' => $candidate->id, 'confirmed_at' => now()->timestamp]]);

        $this->get(route('admin.login'))->assertForbidden();
        $this->post(route('admin.login.store'), ['email' => $candidate->email, 'password' => 'safe-test-password'])->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_expired_admin_write_does_not_change_data(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->candidate()->create();
        $this->actingAs($admin)->withSession(['admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->subMinutes(31)->timestamp]]);

        $this->patch(route('admin.users.status', $candidate), ['current_status' => 'ACTIVE', 'status' => 'BLOCKED', 'reason' => 'Test'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('users', ['id' => $candidate->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('account_status_histories', 0);
    }

    public function test_admin_login_rate_limit_and_csrf_are_enforced(): void
    {
        config(['traffic_guard.enabled' => false]);
        Http::fake(['*/token?grant_type=password' => Http::response(['error_code' => 'invalid_credentials'], 400)]);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->post(route('admin.login.store'), ['email' => 'admin@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post(route('admin.login.store'), ['email' => 'admin@example.test', 'password' => 'wrong'])->assertStatus(429);
        Http::assertSentCount(8);
        $this->app->instance('env', 'local');
        $this->post(route('admin.login.store'), ['email' => 'admin@example.test', 'password' => 'wrong'])->assertStatus(419);
    }

    private function fakeIdentity(User $user): void
    {
        Http::fake(['*/token?grant_type=password' => Http::response([
            'user' => ['id' => $user->id, 'email' => $user->email, 'user_metadata' => ['account_type' => 'admin']],
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 3600, 'token_type' => 'bearer',
        ])]);
    }

    #[DataProvider('passwordEntryPoints')]
    public function test_optional_mfa_password_login_rotates_session_and_opens_admin_without_mfa_calls(string $route): void
    {
        config(['auth.admin_mfa_required' => false]);
        $admin = User::factory()->admin()->create();
        $this->fakeIdentity($admin);
        $this->withSession(['sentinel' => true]);
        $oldId = session()->getId();

        $this->post(route($route), ['email' => $admin->email, 'password' => 'safe-test-password'])
            ->assertRedirect(route('admin.dashboard'))->assertSessionHas('admin_password_confirmation.user_id', $admin->id)
            ->assertSessionMissing('admin_confirmation')->assertSessionMissing('_old_input.password');

        $this->assertNotSame($oldId, session()->getId());
        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.login'))->assertRedirect(route('admin.dashboard'));
        $this->travel(29)->minutes();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->travel(1)->minutes();
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/token?grant_type=password'));
    }

    public static function passwordEntryPoints(): array
    {
        return [['admin.login.store'], ['login.store']];
    }

    #[DataProvider('deniedAccounts')]
    public function test_optional_mfa_still_rejects_non_admin_or_blocked_password_login(string $role, string $status): void
    {
        config(['auth.admin_mfa_required' => false]);
        $user = User::factory()->create(['role' => $role, 'status' => $status]);
        $this->fakeIdentity($user);

        $this->post(route('admin.login.store'), ['email' => $user->email, 'password' => 'safe-test-password'])
            ->assertSessionHasErrors('email')->assertSessionMissing('admin_password_confirmation');

        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public function test_optional_mfa_never_allows_guest_or_wrong_password(): void
    {
        config(['auth.admin_mfa_required' => false]);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        Http::fake(['*/token?grant_type=password' => Http::response(['error_code' => 'invalid_credentials'], 400)]);

        $this->post(route('admin.login.store'), ['email' => 'admin@example.test', 'password' => 'wrong-secret'])
            ->assertSessionHasErrors('email')->assertSessionMissing('admin_password_confirmation')->assertSessionMissing('_old_input.password');

        $this->assertGuest();
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidPasswordMarkers')]
    public function test_optional_mfa_rejects_invalid_password_markers_and_never_executes_admin_write(string $failure): void
    {
        config(['auth.admin_mfa_required' => false]);
        $admin = User::factory()->admin()->create(['is_demo' => true]);
        $candidate = User::factory()->candidate()->create();
        $marker = ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp];
        $session = ['auth_version' => (int) $admin->auth_version];
        if ($failure === 'expired') {
            $marker['confirmed_at'] = now()->subMinutes(30)->timestamp;
        } elseif ($failure === 'future') {
            $marker['confirmed_at'] = now()->addMinute()->timestamp;
        } elseif ($failure === 'wrong-identity') {
            $marker['user_id'] = $candidate->id;
        } elseif ($failure === 'revoked') {
            $admin->increment('auth_version');
        } elseif ($failure === 'blocked') {
            $admin->update(['status' => 'BLOCKED']);
        } elseif ($failure === 'demo') {
            $this->app->instance('env', 'local');
            config(['app.demo_mode' => true]);
            $session['demo_auth'] = true;
            $session['_token'] = 'test-csrf';
        }
        if ($failure !== 'missing') {
            $session['admin_password_confirmation'] = $marker;
        }
        $this->actingAs($admin)->withSession($session);

        $this->patch(route('admin.users.status', $candidate), ['_token' => 'test-csrf', 'current_status' => 'ACTIVE', 'status' => 'BLOCKED', 'reason' => 'Test'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('users', ['id' => $candidate->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('account_status_histories', 0);
        Http::assertNothingSent();
    }

    public static function invalidPasswordMarkers(): array
    {
        return [['missing'], ['expired'], ['future'], ['wrong-identity'], ['revoked'], ['blocked'], ['demo']];
    }

    #[DataProvider('optionalMfaEndpoints')]
    public function test_optional_mfa_endpoints_skip_provider_only_after_password_confirmation(string $method, string $route, bool $confirmed): void
    {
        config(['auth.admin_mfa_required' => false]);
        $admin = User::factory()->admin()->create();
        $session = ['admin_mfa_enrollment' => ['secret' => 'test-setup-secret'], 'admin_mfa_factor' => 'test-factor'];
        if ($confirmed) {
            $session['admin_password_confirmation'] = ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp];
        }

        $response = $this->actingAs($admin)->withSession($session)->{$method}(route($route));

        $response->assertRedirect(route($confirmed ? 'admin.dashboard' : 'admin.login'));
        if ($confirmed) {
            $response->assertSessionMissing('admin_mfa_enrollment')->assertSessionMissing('admin_mfa_factor');
        }
        Http::assertNothingSent();
    }

    public static function optionalMfaEndpoints(): array
    {
        return [['get', 'admin.mfa', true], ['post', 'admin.mfa.enroll', true], ['post', 'admin.mfa.verify', true],
            ['get', 'admin.mfa', false], ['post', 'admin.mfa.enroll', false], ['post', 'admin.mfa.verify', false]];
    }

    public function test_optional_mfa_preserves_existing_verified_session_and_reenabling_restores_mfa_gate(): void
    {
        config(['auth.admin_mfa_required' => false]);
        $admin = User::factory()->admin()->create();
        $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $token = $encode(['alg' => 'ES256']).'.'.$encode(['sub' => $admin->id, 'aal' => 'aal2', 'exp' => now()->addHour()->timestamp]).'.test-signature';
        $this->actingAs($admin)->withSession(['admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp, 'mfa' => true], 'supabase_session' => ['access_token' => $token]]);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->withSession(['admin_confirmation' => null, 'admin_password_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp]]);
        $this->get(route('admin.dashboard'))->assertOk();

        config(['auth.admin_mfa_required' => true]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa'));
        Http::assertNothingSent();
    }

    #[DataProvider('optionalNonAdminRoles')]
    public function test_optional_mfa_never_elevates_an_existing_non_admin_session(string $role): void
    {
        config(['auth.admin_mfa_required' => false]);
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession(['admin_password_confirmation' => ['user_id' => $user->id, 'confirmed_at' => now()->timestamp]]);

        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.mfa'))->assertForbidden();
        $this->get(route('admin.login'))->assertForbidden();

        Http::assertNothingSent();
    }

    public static function optionalNonAdminRoles(): array
    {
        return [['candidate'], ['employer']];
    }

    public function test_optional_mfa_does_not_turn_an_unverified_legacy_marker_into_confirmation(): void
    {
        config(['auth.admin_mfa_required' => false]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession(['admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp, 'mfa' => false]])
            ->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));

        Http::assertNothingSent();
    }

    #[DataProvider('optionalMfaLocales')]
    public function test_optional_mfa_login_explains_password_only_in_each_locale(string $locale, string $text): void
    {
        config(['auth.admin_mfa_required' => false]);

        $this->withSession(['locale' => $locale])->get(route('admin.login'))->assertOk()->assertSee($text)->assertSee('autocomplete="current-password"', false);

        Http::assertNothingSent();
    }

    public static function optionalMfaLocales(): array
    {
        return [['vi', 'Đăng nhập bằng email và mật khẩu tài khoản quản trị.'],
            ['en', 'Sign in with your administrator email and password.'],
            ['ja', '管理者アカウントのメールアドレスとパスワードでログインしてください。']];
    }
}
