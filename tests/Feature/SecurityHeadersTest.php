<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_existing_stricter_policy_is_preserved(): void
    {
        Route::get('/security-test-sandbox', fn () => response('Protected document')
            ->header('Content-Security-Policy', "default-src 'none'; sandbox"));

        $response = $this->get('/security-test-sandbox')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $policies = $response->headers->all('Content-Security-Policy');
        $this->assertContains("default-src 'none'; sandbox", $policies);
        $this->assertContains("frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'", $policies);
    }

    public function test_private_disk_has_no_direct_download_or_upload_route(): void
    {
        $this->get('/storage/admin-login.txt')->assertNotFound();
        $this->get('/storage/app/private/admin-login.txt')->assertNotFound();
        $this->get('/storage/admin-login.txt?signature=invalid')->assertNotFound();
        $this->put('/storage/admin-login.txt?upload=true', ['content' => 'test'])->assertNotFound();
    }

    #[DataProvider('responses')]
    public function test_security_headers_cover_success_redirect_and_error_responses(string $path, int $status): void
    {
        Http::preventStrayRequests();
        $response = $this->get($path)->assertStatus($status)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $policy = $response->headers->get('Content-Security-Policy');
        foreach (["frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'", "object-src 'none'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public static function responses(): array
    {
        return [
            'public form' => ['/login', 200],
            'admin form' => ['/admin/login', 200],
            'guest admin redirect' => ['/admin', 302],
            'missing route' => ['/not-a-real-route', 404],
            'unauthenticated API' => ['/api/me', 401],
        ];
    }
}
