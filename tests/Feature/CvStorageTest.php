<?php

namespace Tests\Feature;

use App\Models\CvDocument;
use App\Services\CvMalwareScanner;
use App\Services\CvStorage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CvStorageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://test-project.supabase.co/storage/v1';

    private const KEY = 'cvs/550e8400-e29b-41d4-a716-446655440000.pdf';

    private const PDF = "%PDF-1.4\nSynthetic curriculum vitae\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(['status' => 'CLEAN', 'engine' => 'test', 'reason' => null]));
        config(['supabase.url' => 'https://test-project.supabase.co', 'supabase.secret_key' => 'sb_secret_test', 'supabase.cv_disk' => 'supabase', 'supabase.storage_bucket' => 'internship-cvs']);
        Http::preventStrayRequests();
    }

    public function test_new_local_upload_can_be_read_and_compensated_without_cloud_requests(): void
    {
        config(['supabase.cv_disk' => 'local']);
        Storage::fake('local');
        $service = app(CvStorage::class);
        $stored = $service->store(UploadedFile::fake()->createWithContent('cv.pdf', self::PDF));
        $this->assertSame('local', $stored['disk']);
        $this->assertSame(self::PDF, Storage::disk('local')->get($stored['key']));
        $service->delete($stored['disk'], $stored['key']);
        Storage::disk('local')->assertMissing($stored['key']);
        Http::assertNothingSent();
    }

    public function test_preexisting_cv_uses_local_disk_even_when_new_uploads_choose_cloud(): void
    {
        Storage::fake('local');
        $cv = CvDocument::factory()->create(['storage_key' => 'cvs/demo/example/internship.pdf']);
        Storage::disk('local')->put($cv->storage_key, self::PDF);
        $this->assertSame('local', $cv->fresh()->disk);
        $response = app(CvStorage::class)->response($cv->fresh());
        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();
        $this->assertSame(self::PDF, ob_get_clean());
        Http::assertNothingSent();
    }

    public function test_remote_upload_sends_exact_pdf_without_overwriting_and_returns_remote_location(): void
    {
        Http::fake([self::URL.'/object/internship-cvs/cvs/*.pdf' => Http::response(['Key' => 'uploaded'], 200)]);
        $stored = app(CvStorage::class)->store(UploadedFile::fake()->createWithContent('cv.pdf', self::PDF));
        $this->assertSame('supabase', $stored['disk']);
        $this->assertMatchesRegularExpression('/^cvs\/[0-9a-f-]{36}\.pdf$/', $stored['key']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL.'/object/internship-cvs/'.$stored['key']
            && $request->hasHeader('apikey', 'sb_secret_test') && ! $request->hasHeader('Authorization')
            && $request->hasHeader('Content-Type', 'application/pdf') && $request->hasHeader('x-upsert', 'false')
            && $request->body() === self::PDF);
        Http::assertSentCount(1);
    }

    public function test_remote_download_proxies_bytes_privately_without_exposing_storage_url_or_key(): void
    {
        Http::fake([self::URL.'/object/authenticated/internship-cvs/'.self::KEY => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf'])]);
        $cv = new CvDocument(['disk' => 'supabase', 'storage_key' => self::KEY]);
        $cv->id = 42;
        $response = app(CvStorage::class)->response($cv);
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('inline; filename="cv-42.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertFalse($response->headers->has('Location'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->hasHeader('apikey', 'sb_secret_test'));
        Http::assertSentCount(1);
    }

    public function test_compensation_deletes_only_its_generated_object(): void
    {
        Http::fake([self::URL.'/object/internship-cvs' => Http::response([], 200)]);
        app(CvStorage::class)->delete('supabase', self::KEY);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->data() === ['prefixes' => [self::KEY]]);
        Http::assertSentCount(1);
    }

    #[DataProvider('remoteErrors')]
    public function test_cloud_upload_failure_is_visible_and_never_falls_back_to_local(int $status): void
    {
        Storage::fake('local');
        Http::fake([self::URL.'/object/internship-cvs/cvs/*.pdf' => Http::response(['message' => 'private provider detail'], $status)]);
        try {
            app(CvStorage::class)->store(UploadedFile::fake()->createWithContent('cv.pdf', self::PDF));
            $this->fail('Upload should fail.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringNotContainsString('private provider detail', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertSentCount(1);
    }

    public static function remoteErrors(): array
    {
        return ['unauthorized' => [401], 'bucket missing' => [404], 'collision' => [409], 'rate limited' => [429], 'server error' => [500], 'redirect' => [302]];
    }

    public function test_connection_failure_returns_sanitized_503(): void
    {
        Http::fake([self::URL.'/object/internship-cvs/cvs/*.pdf' => Http::failedConnection()]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Chưa kết nối được kho CV Supabase. Vui lòng thử lại.');
        app(CvStorage::class)->store(UploadedFile::fake()->createWithContent('cv.pdf', self::PDF));
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_stops_before_transmitting_secret(string $setting, string $value): void
    {
        config([$setting => $value]);
        try {
            app(CvStorage::class)->store(UploadedFile::fake()->createWithContent('cv.pdf', self::PDF));
            $this->fail('Invalid configuration should fail.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public static function invalidConfiguration(): array
    {
        return ['missing secret' => ['supabase.secret_key', ''], 'masked secret' => ['supabase.secret_key', 'sb_secret_••••'],
            'insecure endpoint' => ['supabase.url', 'http://test-project.supabase.co'], 'path injection' => ['supabase.storage_bucket', '../other'], 'unsupported disk' => ['supabase.cv_disk', 'public']];
    }

    public function test_download_rejects_non_pdf_provider_response(): void
    {
        Http::fake([self::URL.'/object/authenticated/internship-cvs/'.self::KEY => Http::response('<html>Error</html>')]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Tệp CV lưu trữ không hợp lệ.');
        app(CvStorage::class)->response(new CvDocument(['disk' => 'supabase', 'storage_key' => self::KEY]));
    }

    public function test_path_traversal_is_rejected_before_storage_access(): void
    {
        try {
            app(CvStorage::class)->delete('supabase', 'cvs/../other.pdf');
            $this->fail('Path traversal should fail.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('downloadErrors')]
    public function test_remote_download_failures_do_not_return_fake_pdf(int $upstreamStatus, int $expectedStatus): void
    {
        Http::fake([self::URL.'/object/authenticated/internship-cvs/'.self::KEY => Http::response('provider private detail', $upstreamStatus)]);
        try {
            app(CvStorage::class)->response(new CvDocument(['disk' => 'supabase', 'storage_key' => self::KEY]));
            $this->fail('Download should fail.');
        } catch (HttpException $exception) {
            $this->assertSame($expectedStatus, $exception->getStatusCode());
            $this->assertStringNotContainsString('provider private detail', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function downloadErrors(): array
    {
        return ['missing' => [404, 404], 'unauthorized' => [403, 503], 'failure' => [500, 503]];
    }

    public function test_compensation_failure_is_reported_without_provider_details(): void
    {
        Http::fake([self::URL.'/object/internship-cvs' => Http::response('private provider detail', 500)]);
        try {
            app(CvStorage::class)->delete('supabase', self::KEY);
            $this->fail('Compensation should fail.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringNotContainsString('private provider detail', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }
}
