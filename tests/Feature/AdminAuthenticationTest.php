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
}
