<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function languages(): array
    {
        return [['vi', 'Đăng nhập', 'Bước đầu nhỏ.'], ['en', 'Sign in', 'Small first steps.'], ['ja', 'ログイン', '小さな一歩。']];
    }

    #[DataProvider('languages')]
    public function test_language_choice_persists_and_translates_public_pages(string $locale, string $login, string $hero): void
    {
        $job = JobPosting::factory()->accepting()->create(['title' => 'Thực tập Laravel gốc']);
        $this->post(route('locale.update'), ['locale' => $locale, 'return_to' => '/jobs?q=Laravel'])
            ->assertStatus(303)->assertRedirect('/jobs?q=Laravel')->assertSessionHas('locale', $locale)->assertCookie('locale', $locale);
        $this->get('/login')->assertOk()->assertHeader('Content-Language', $locale)->assertSee('lang="'.$locale.'"', false)->assertSee($login);
        $this->get('/')->assertOk()->assertSee($hero)->assertSee($job->title);
        $this->get(route('jobs.show', $job))->assertOk()->assertSee($job->title);
        $this->get(route('companies.show', $job->company))->assertOk()->assertSee($job->company->name);
    }

    public function test_language_cookie_works_after_session_is_lost(): void
    {
        $this->withCookie('locale', 'ja')->get('/login')->assertOk()->assertSee('lang="ja"', false)->assertSee('ログイン');
    }

    public function test_invalid_locale_is_rejected_without_changing_the_session(): void
    {
        $this->withSession(['locale' => 'en'])->postJson(route('locale.update'), ['locale' => '../../private'])
            ->assertUnprocessable()->assertJsonValidationErrors('locale')->assertSessionHas('locale', 'en');
    }

    public static function unsafeDestinations(): array
    {
        return [['https://evil.test'], ['//evil.test'], ['/\\evil.test'], ['/%2Fevil.test'], ["/\r\nevil.test"], ['/language']];
    }

    #[DataProvider('unsafeDestinations')]
    public function test_language_switch_never_redirects_to_an_external_destination(string $destination): void
    {
        $this->post(route('locale.update'), ['locale' => 'en', 'return_to' => $destination])->assertRedirect('/');
    }

    public function test_tampered_locale_cookie_falls_back_to_vietnamese(): void
    {
        $this->withCookie('locale', 'xx')->get('/login')->assertOk()->assertSee('lang="vi"', false)->assertSee('Đăng nhập');
    }

    public function test_translated_validation_errors_do_not_call_auth_provider(): void
    {
        Http::preventStrayRequests();
        $response = $this->withSession(['locale' => 'ja'])->postJson(route('login.store'), ['email' => 'invalid', 'password' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertStringContainsString('メール', $response->json('errors.email.0'));
        Http::assertNothingSent();
    }

    public function test_locale_switch_does_not_grant_admin_access(): void
    {
        $this->withSession(['locale' => 'en'])->get('/admin')->assertRedirect(route('admin.login'));
    }

    #[TestWith(['login.store', 8])]
    #[TestWith(['register.store', 5])]
    public function test_language_switches_do_not_consume_authentication_attempts(string $route, int $limit): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->post(route('locale.update'), ['locale' => $attempt % 2 === 0 ? 'en' : 'ja'])
                ->assertStatus(303);
        }

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->postJson(route($route), [])
                ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        }

        $this->postJson(route($route), [])->assertStatus(429)->assertHeader('Retry-After', '60');
        Http::assertNothingSent();
    }

    public function test_language_switches_keep_their_own_thirty_per_minute_limit(): void
    {
        $this->freezeTime();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post(route('locale.update'), ['locale' => $attempt % 2 === 0 ? 'en' : 'ja'])
                ->assertStatus(303);
        }

        $this->postJson(route('locale.update'), ['locale' => 'vi'])
            ->assertStatus(429)->assertHeader('Retry-After', '60')->assertSessionHas('locale', 'ja');

        $this->travel(61)->seconds();
        $this->post(route('locale.update'), ['locale' => 'vi'])
            ->assertStatus(303)->assertSessionHas('locale', 'vi');
    }

    #[TestWith(['vi'])]
    #[TestWith(['en'])]
    #[TestWith(['ja'])]
    public function test_workspaces_render_in_each_language_without_changing_business_data(string $locale): void
    {
        $candidate = CandidateProfile::factory()->create(['full_name' => 'Nguyễn Minh An']);
        $employer = Company::factory()->create();
        $admin = User::factory()->admin()->create();
        $cases = [
            [$candidate->user, ['/candidate', '/candidate/profile', '/candidate/cvs', '/candidate/applications', '/candidate/notifications', '/candidate/saved-searches', '/candidate/saved-jobs', '/candidate/job-history']],
            [$employer->owner, ['/employer', '/employer/company', '/employer/jobs', '/employer/jobs/create']],
            [$admin, ['/admin', '/admin/users', '/admin/jobs', '/admin/reports', '/admin/statistics']],
        ];
        foreach ($cases as [$user, $paths]) {
            $this->actingAs($user)->withSession(array_merge(['locale' => $locale], $user->role === 'admin' ? $this->adminMfaSession($user) : []));
            foreach ($paths as $path) {
                $response = $this->get($path)->assertOk()->assertSee('lang="'.$locale.'"', false);
                $this->assertDoesNotMatchRegularExpression('/(?:workspace|site|ui)\.[a-z_]+/', $response->getContent());
            }
        }
        $this->assertDatabaseHas('candidate_profiles', ['user_id' => $candidate->user_id, 'full_name' => 'Nguyễn Minh An']);
    }
}
