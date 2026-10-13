<?php

namespace Tests\Feature;

use App\Exceptions\SupabaseRequestException;
use App\Services\SupabaseAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrafficGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_burst_is_limited_and_recovers_after_window_without_trusting_forwarded_ip(): void
    {
        $this->freezeTime();
        config(['traffic_guard.requests_per_minute' => 2]);
        $this->get('/login')->assertOk();
        $this->withHeader('X-Forwarded-For', '198.51.100.2')->get('/login')->assertOk();
        $this->withHeader('X-Forwarded-For', '198.51.100.3')->get('/login')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travel(61)->seconds();
        $this->get('/login')->assertOk();
    }

    public function test_repeated_private_path_probes_cool_down_only_source_ip(): void
    {
        $this->freezeTime();
        config(['traffic_guard.probe_threshold' => 2]);
        $this->get('/.env')->assertNotFound();
        $this->get('/.git/config')->assertNotFound();
        $this->get('/login')->assertStatus(429)->assertHeader('Retry-After', '300');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->get('/login')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->travel(301)->seconds();
        $this->get('/login')->assertOk();
    }

    public function test_honeypot_rejects_bot_before_external_signup_and_is_hidden_in_form(): void
    {
        $this->mock(SupabaseAuthService::class)->shouldNotReceive('signup');
        $this->get('/register')->assertOk()->assertSee('hidden aria-hidden="true"', false)->assertSee('name="contact_website"', false);
        $this->post('/register', ['contact_website' => 'https://spam.invalid'])->assertStatus(422)->assertJsonPath('error', 'REGISTRATION_REJECTED');
    }

    public function test_blank_honeypot_allows_normal_registration(): void
    {
        $this->mock(SupabaseAuthService::class)->shouldReceive('signup')->once()->andReturn([]);
        $this->post('/register', ['name' => 'Ứng viên thử', 'email' => 'human@example.test', 'role' => 'candidate', 'password' => 'password123!', 'password_confirmation' => 'password123!', 'contact_website' => ''])->assertRedirect(route('login'));
    }

    #[DataProvider('loginPaths')]
    public function test_wrong_credentials_cooldown_is_account_and_ip_scoped_and_expires(string $path): void
    {
        $this->freezeTime();
        config(['traffic_guard.failure_threshold' => 2]);
        $this->mock(SupabaseAuthService::class)->shouldReceive('signIn')->times(4)->andThrow(new SupabaseRequestException('AUTH_FAILED', 401));
        $data = ['email' => 'wrong@example.test', 'password' => 'wrong-password'];
        $this->post($path, $data)->assertSessionHasErrors('email');
        $this->post($path, $data)->assertSessionHasErrors('email');
        $this->post($path, $data)->assertStatus(429)->assertHeader('Retry-After', '300');
        $this->post($path, ['email' => 'another@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->travel(301)->seconds();
        $this->post($path, $data)->assertSessionHasErrors('email');
    }

    public static function loginPaths(): array
    {
        return [['/login'], ['/admin/login']];
    }

    public function test_auth_outage_does_not_count_as_bad_credentials(): void
    {
        config(['traffic_guard.failure_threshold' => 1]);
        $this->mock(SupabaseAuthService::class)->shouldReceive('signIn')->twice()->andThrow(new SupabaseRequestException('AUTH_UNAVAILABLE', 503));
        $data = ['email' => 'human@example.test', 'password' => 'password123!'];
        $this->post('/login', $data)->assertSessionHasErrors('email');
        $this->post('/login', $data)->assertSessionHasErrors('email');
    }

    public function test_distributed_auth_attempts_share_normalized_account_limit(): void
    {
        config(['traffic_guard.account_attempts' => 1]);
        $this->mock(SupabaseAuthService::class)->shouldReceive('signIn')->once()->andThrow(new SupabaseRequestException('AUTH_FAILED', 401));
        $this->post('/login', ['email' => 'Human@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->post('/admin/login', ['email' => 'human@example.test', 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_normal_job_text_is_not_rejected_by_injection_keyword_filters(): void
    {
        Route::post('/guard-description-test', fn () => response()->json(['accepted' => true]));
        $this->postJson('/guard-description-test', ['description' => 'Tìm thực tập SQL: SELECT, UNION, HTML <script> và .env là kiến thức cần học.'])->assertOk()->assertJsonPath('accepted', true);
    }

    public function test_can_disable_guard_explicitly(): void
    {
        config(['traffic_guard.enabled' => false, 'traffic_guard.requests_per_minute' => 1]);
        $this->get('/login')->assertOk();
        $this->get('/login')->assertOk();
    }
}
