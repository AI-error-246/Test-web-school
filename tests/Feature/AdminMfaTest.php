<?php

namespace Tests\Feature;

use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminMfaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const AUTH = 'https://auth-test.supabase.co/auth/v1';

    private const FACTOR = 'a23ab549-20bb-4dc9-bbf1-d5bbf83bf366';

    private const CHALLENGE = 'b38b44fc-7608-420a-8f90-144fce1777b9';

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url' => 'https://auth-test.supabase.co', 'supabase.publishable_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    #[DataProvider('passwordLoginPaths')]
    public function test_both_password_entry_points_require_mfa_before_admin_access(string $path): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/token?grant_type=password' => Http::response($this->providerSession($admin, 'aal1'))]);
        $this->withSession(['admin_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp, 'mfa' => true], 'admin_mfa_enrollment' => ['secret' => 'old-secret']]);
        $oldSession = session()->getId();

        $this->post($path, ['email' => $admin->email, 'password' => 'test-password'])
            ->assertRedirect(route('admin.mfa'))->assertSessionHas('admin_password_confirmation.user_id', $admin->id)
            ->assertSessionMissing('admin_confirmation')->assertSessionMissing('admin_mfa_enrollment')->assertSessionMissing('_old_input.password');

        $this->assertAuthenticatedAs($admin);
        $this->assertNotSame($oldSession, session()->getId());
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa'));
        Http::assertSentCount(1);
    }

    public static function passwordLoginPaths(): array
    {
        return [['/login'], ['/admin/login']];
    }

    public function test_verified_factor_shows_code_form_and_discards_enrollment_secret(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::response($this->factorIdentity($admin))]);

        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_enrollment' => ['id' => self::FACTOR, 'secret' => self::SECRET, 'qr_code' => '<svg/>']])
            ->get(route('admin.mfa'))->assertOk()->assertSee('autocomplete="one-time-code"', false)
            ->assertDontSee(self::SECRET)->assertSessionHas('admin_mfa_factor', self::FACTOR)->assertSessionMissing('admin_mfa_enrollment');

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'GET' && $request->url() === self::AUTH.'/user');
    }

    public function test_admin_without_verified_factor_must_explicitly_start_enrollment(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::response(['id' => $admin->id, 'factors' => []])]);

        $this->actingAs($admin)->withSession($this->pendingSession($admin))->get(route('admin.mfa'))
            ->assertOk()->assertSee(route('admin.mfa.enroll'), false)->assertDontSee('name="code"', false)
            ->assertSessionMissing('admin_mfa_factor')->assertSessionMissing('admin_confirmation');

        Http::assertSentCount(1);
    }

    public function test_enrollment_is_private_and_qr_is_an_image_instead_of_inline_svg(): void
    {
        $admin = User::factory()->admin()->create();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("provider-svg")</script></svg>';
        Http::fake([
            self::AUTH.'/user' => Http::response(['id' => $admin->id, 'factors' => []]),
            self::AUTH.'/factors' => Http::response(['id' => self::FACTOR, 'type' => 'totp', 'totp' => ['secret' => self::SECRET, 'qr_code' => $svg]]),
        ]);
        $this->actingAs($admin)->withSession($this->pendingSession($admin));

        $this->post(route('admin.mfa.enroll'))->assertRedirect(route('admin.mfa'))
            ->assertSessionHas('admin_mfa_enrollment.secret', self::SECRET)->assertSessionMissing('admin_confirmation');
        $this->get(route('admin.mfa'))->assertOk()->assertSee('data:image/svg+xml;base64,'.base64_encode($svg), false)
            ->assertDontSee($svg, false)->assertSee(self::SECRET)->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('admin.mfa.enroll'))->assertRedirect(route('admin.mfa'));

        $this->assertCount(1, Http::recorded(fn (ClientRequest $request) => $request->url() === self::AUTH.'/factors'));
        Http::assertSentCount(4);
    }

    public function test_existing_verified_factor_cannot_be_replaced_through_enrollment(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::response($this->factorIdentity($admin))]);

        $this->actingAs($admin)->withSession($this->pendingSession($admin))->post(route('admin.mfa.enroll'))
            ->assertRedirect(route('admin.mfa'))->assertSessionMissing('admin_mfa_enrollment');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $request) => $request->method() === 'POST');
    }

    public function test_factor_discovery_failure_never_offers_an_unverified_bypass(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([self::AUTH.'/user' => Http::response(['message' => 'private-provider-diagnostic'], 503)]);

        $this->actingAs($admin)->withSession($this->pendingSession($admin))->get(route('admin.mfa'))
            ->assertStatus(503)->assertDontSee('private-provider-diagnostic')->assertSessionMissing('admin_confirmation');

        Http::assertSentCount(1);
    }

    public function test_failed_enrollment_does_not_store_a_secret_or_grant_access(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([
            self::AUTH.'/user' => Http::response(['id' => $admin->id, 'factors' => []]),
            self::AUTH.'/factors' => Http::response(['message' => 'private-provider-diagnostic'], 503),
        ]);

        $this->actingAs($admin)->withSession($this->pendingSession($admin))->post(route('admin.mfa.enroll'))
            ->assertSessionHasErrors('mfa')->assertSessionMissing('admin_mfa_enrollment')->assertSessionMissing('admin_confirmation');

        Http::assertSentCount(2);
    }

    public function test_valid_code_rotates_session_clears_setup_data_and_grants_timed_admin_access(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $this->fakeVerification($admin);
        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR, 'admin_mfa_enrollment' => ['secret' => self::SECRET]]);
        $oldSession = session()->getId();

        $this->post(route('admin.mfa.verify'), ['code' => '123456'])->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('admin_confirmation.mfa', true)->assertSessionHas('admin_confirmation.user_id', $admin->id)
            ->assertSessionMissing('admin_password_confirmation')->assertSessionMissing('admin_mfa_factor')->assertSessionMissing('admin_mfa_enrollment')
            ->assertSessionMissing('_old_input.code');

        $this->assertNotSame($oldSession, session()->getId());
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.mfa'))->assertRedirect(route('admin.dashboard'));
        $this->travel(30)->minutes();
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_code_format_stops_before_provider_request(mixed $code): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR]);

        $this->post(route('admin.mfa.verify'), ['code' => $code])->assertSessionHasErrors('code')
            ->assertSessionMissing('admin_confirmation')->assertSessionMissing('_old_input.code');

        Http::assertNothingSent();
    }

    public static function invalidCodes(): array
    {
        return ['missing' => [null], 'wrong length' => ['12345'], 'non-numeric' => ['12a456'], 'array' => [['123456']]];
    }

    #[DataProvider('providerFailureStatuses')]
    public function test_rejected_code_or_provider_error_never_confirms_mfa_or_flashes_code(int $status): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response(['message' => 'private-provider-diagnostic'], $status),
        ]);

        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR])
            ->from(route('admin.mfa'))->post(route('admin.mfa.verify'), ['code' => '654321'])
            ->assertSessionHasErrors('code')->assertSessionMissing('admin_confirmation')->assertSessionMissing('_old_input.code')
            ->assertSessionHas('admin_password_confirmation.user_id', $admin->id);

        $this->assertStringNotContainsString('private-provider-diagnostic', session('errors')->first('code'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.mfa'));
        Http::assertSentCount(2);
    }

    public static function providerFailureStatuses(): array
    {
        return [[400], [429], [503]];
    }

    public function test_provider_identity_mismatch_is_rejected_even_with_a_valid_aal2_response(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $this->fakeVerification($other);

        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR])
            ->post(route('admin.mfa.verify'), ['code' => '123456'])->assertSessionHasErrors('code')->assertSessionMissing('admin_confirmation');

        $this->assertAuthenticatedAs($admin);
        Http::assertSentCount(2);
    }

    public function test_aal1_response_cannot_upgrade_admin_access(): void
    {
        $admin = User::factory()->admin()->create();
        $this->fakeVerification($admin, 'aal1');

        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR])
            ->post(route('admin.mfa.verify'), ['code' => '123456'])->assertSessionHasErrors('code')->assertSessionMissing('admin_confirmation');

        Http::assertSentCount(2);
    }

    public function test_browser_payload_cannot_choose_a_different_factor_or_supply_its_own_token(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $this->fakeVerification($admin);
        $serverToken = $this->providerSession($admin, 'aal1')['access_token'];

        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR])
            ->post(route('admin.mfa.verify'), ['code' => '123456', 'factor' => self::CHALLENGE, 'access_token' => 'browser-injected-token', 'mfa' => true])
            ->assertRedirect(route('admin.dashboard'));

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::AUTH.'/factors/'.self::FACTOR.'/verify'
            && $request->hasHeader('Authorization', 'Bearer '.$serverToken)
            && $request->data() === ['challenge_id' => self::CHALLENGE, 'code' => '123456']);
    }

    public function test_verify_without_server_selected_factor_returns_to_setup_without_remote_call(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession($this->pendingSession($admin))->post(route('admin.mfa.verify'), ['code' => '123456', 'factor' => self::FACTOR])
            ->assertRedirect(route('admin.mfa'))->assertSessionMissing('admin_confirmation');

        Http::assertNothingSent();
    }

    #[DataProvider('expiredPasswordActions')]
    public function test_password_confirmation_expires_after_ten_minutes(string $method, string $route): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR, 'admin_mfa_enrollment' => ['secret' => self::SECRET]]);
        $this->travel(10)->minutes();

        $this->{$method}(route($route), ['code' => '123456'])->assertRedirect(route('admin.login'))
            ->assertSessionMissing('admin_password_confirmation')->assertSessionMissing('admin_mfa_enrollment')->assertSessionMissing('admin_confirmation');

        Http::assertNothingSent();
    }

    public static function expiredPasswordActions(): array
    {
        return [['get', 'admin.mfa'], ['post', 'admin.mfa.enroll'], ['post', 'admin.mfa.verify']];
    }

    #[DataProvider('invalidConfirmations')]
    public function test_stale_or_incomplete_mfa_confirmation_cannot_open_admin(string $failure): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $state = $this->adminMfaSession($admin);
        if ($failure === 'password-only') {
            unset($state['admin_confirmation']['mfa']);
        } elseif ($failure === 'aal1') {
            $state['supabase_session']['access_token'] = $this->token($admin->id, 'aal1');
        } elseif ($failure === 'expired-token') {
            $state['supabase_session']['access_token'] = $this->token($admin->id, 'aal2', now()->timestamp);
        } elseif ($failure === 'wrong-token-user') {
            $state['supabase_session']['access_token'] = $this->token((string) User::factory()->admin()->make()->id, 'aal2');
        } elseif ($failure === 'future-confirmation') {
            $state['admin_confirmation']['confirmed_at'] = now()->addSecond()->timestamp;
        } else {
            $state['admin_confirmation']['user_id'] = User::factory()->admin()->make()->id;
        }

        $this->actingAs($admin)->withSession($state)->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'))->assertSessionMissing('admin_confirmation');

        Http::assertNothingSent();
    }

    public static function invalidConfirmations(): array
    {
        return array_map(fn (string $reason): array => [$reason], ['password-only', 'aal1', 'expired-token', 'wrong-token-user', 'future-confirmation', 'wrong-confirmation-user']);
    }

    public function test_pending_admin_cannot_read_shared_cv_or_application_routes(): void
    {
        $admin = User::factory()->admin()->create();
        $cv = CvDocument::factory()->create();
        $application = JobApplication::factory()->create(['cv_id' => $cv->id, 'candidate_id' => $cv->candidate_id, 'name_snapshot' => 'Private applicant details']);
        $this->mock(CvStorage::class)->shouldNotReceive('response');
        $this->actingAs($admin)->withSession($this->pendingSession($admin));

        $this->get(route('cvs.show', $cv))->assertRedirect(route('admin.mfa'))->assertDontSee('application/pdf');
        $this->get(route('applications.show', $application))->assertRedirect(route('admin.mfa'))->assertDontSee('Private applicant details');

        Http::assertNothingSent();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_access_any_mfa_endpoint(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->withSession($this->pendingSession($user));

        $this->get(route('admin.mfa'))->assertForbidden();
        $this->post(route('admin.mfa.enroll'))->assertForbidden();
        $this->post(route('admin.mfa.verify'), ['code' => '123456'])->assertForbidden();

        Http::assertNothingSent();
    }

    public static function nonAdminRoles(): array
    {
        return [['candidate'], ['employer']];
    }

    public function test_guest_is_sent_to_admin_login_before_any_mfa_call(): void
    {
        $this->get(route('admin.mfa'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.mfa.enroll'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.mfa.verify'), ['code' => '123456'])->assertRedirect(route('admin.login'));

        Http::assertNothingSent();
    }

    #[DataProvider('accountChanges')]
    public function test_account_change_during_pending_mfa_prevents_verification(string $change): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR]);
        if ($change === 'blocked') {
            $admin->update(['status' => 'BLOCKED']);
        } elseif ($change === 'role') {
            $admin->update(['role' => 'candidate']);
        } else {
            User::whereKey($admin->id)->increment('auth_version');
        }

        $response = $this->post(route('admin.mfa.verify'), ['code' => '123456']);
        if ($change === 'role') {
            $response->assertForbidden();
        } else {
            $response->assertRedirect(route('admin.login'))->assertSessionMissing('admin_password_confirmation');
            $this->assertGuest();
        }

        Http::assertNothingSent();
    }

    public static function accountChanges(): array
    {
        return [['blocked'], ['role'], ['auth-version']];
    }

    public function test_code_attempt_limit_follows_account_across_ips_and_expires(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        Http::fake([
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response(['error_code' => 'mfa_verification_failed'], 400),
        ]);
        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->post(route('admin.mfa.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])->post(route('admin.mfa.verify'), ['code' => '123456'])->assertStatus(429)->assertHeader('Retry-After');
        Http::assertSentCount(10);
        $this->travel(301)->seconds();
        $this->post(route('admin.mfa.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        Http::assertSentCount(12);
    }

    public function test_mfa_mutations_require_csrf_outside_the_test_environment(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR]);
        $this->app->instance('env', 'local');

        $this->post(route('admin.mfa.enroll'))->assertStatus(419);
        $this->post(route('admin.mfa.verify'), ['code' => '123456'])->assertStatus(419);

        Http::assertNothingSent();
    }

    public function test_refresh_that_downgrades_assurance_revokes_admin_access(): void
    {
        $admin = User::factory()->admin()->create();
        $state = $this->adminMfaSession($admin);
        $state['supabase_session']['expires_at'] = time() - 1;
        Http::fake([self::AUTH.'/token?grant_type=refresh_token' => Http::response($this->providerSession($admin, 'aal1'))]);
        $this->actingAs($admin)->withSession($state);
        $this->app->instance('env', 'local');

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'))->assertSessionMissing('admin_confirmation');

        Http::assertSentCount(1);
    }

    #[DataProvider('intendedDestinations')]
    public function test_successful_verification_only_returns_to_safe_admin_destinations(string $path, bool $allowed): void
    {
        $admin = User::factory()->admin()->create();
        $this->fakeVerification($admin);
        $destination = str_starts_with($path, 'https:') ? $path : url($path);

        $this->actingAs($admin)->withSession([...$this->pendingSession($admin), 'admin_mfa_factor' => self::FACTOR, 'url.intended' => $destination])
            ->post(route('admin.mfa.verify'), ['code' => '123456'])
            ->assertRedirect($allowed ? $destination : route('admin.dashboard'))->assertSessionMissing('url.intended');

        Http::assertSentCount(2);
    }

    public static function intendedDestinations(): array
    {
        return [['/admin/users', true], ['https://external.example.test/admin/users', false], ['/candidate', false], ['/admin/mfa', false], ['/admin/login', false], ['/admin-other', false]];
    }

    private function pendingSession(User $admin): array
    {
        return [
            'auth_version' => (int) $admin->auth_version,
            'admin_password_confirmation' => ['user_id' => $admin->id, 'confirmed_at' => now()->timestamp],
            'supabase_session' => [...$this->providerSession($admin, 'aal1'), 'expires_at' => now()->addHour()->timestamp],
        ];
    }

    private function providerSession(User $admin, string $assurance): array
    {
        return ['user' => ['id' => $admin->id, 'email' => $admin->email], 'access_token' => $this->token($admin->id, $assurance), 'refresh_token' => 'test-refresh-token', 'expires_in' => 3600, 'token_type' => 'bearer'];
    }

    private function token(string $userId, string $assurance, ?int $expires = null): string
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['sub' => $userId, 'aal' => $assurance, 'exp' => $expires ?? now()->addHour()->timestamp])), '+/', '-_'), '=');

        return 'eyJhbGciOiJIUzI1NiJ9.'.$claims.'.test-signature';
    }

    private function factorIdentity(User $admin): array
    {
        return ['id' => $admin->id, 'factors' => [['id' => self::FACTOR, 'factor_type' => 'totp', 'status' => 'verified', 'friendly_name' => 'Test authenticator']]];
    }

    private function challenge(): array
    {
        return ['id' => self::CHALLENGE, 'type' => 'totp', 'expires_at' => now()->addHour()->timestamp];
    }

    private function fakeVerification(User $admin, string $assurance = 'aal2'): void
    {
        Http::fake([
            self::AUTH.'/factors/'.self::FACTOR.'/challenge' => Http::response($this->challenge()),
            self::AUTH.'/factors/'.self::FACTOR.'/verify' => Http::response($this->providerSession($admin, $assurance)),
        ]);
    }
}
