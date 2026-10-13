<?php

namespace Tests\Feature;

use App\Console\Commands\TransferLocalToSupabase;
use App\Services\CvMalwareScanner;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferLocalToSupabaseTest extends TestCase
{
    public function test_refuses_implicit_execution_and_wrong_database_without_network_calls(): void
    {
        Http::preventStrayRequests();
        $this->artisan('app:transfer-local-to-supabase')->assertFailed();
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertSame('local', config('supabase.cv_disk'));
    }

    public function test_copies_all_relationships_archived_cvs_and_histories_and_reruns_without_duplication(): void
    {
        $this->prepareDatabases();
        DB::table('cv_documents')->where('id', 1)->update(['archived_at' => '2026-01-01 00:00:00']);
        $this->fakeCloud();
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->times(4)->andReturn(['status' => 'CLEAN', 'engine' => 'test', 'reason' => null]));
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertSuccessful();
        $journal = json_decode(Storage::disk('local')->get('local-cloud-transfer.json'), true);
        $candidate = $journal['users']['11111111-1111-4111-8111-111111111111']['id'];
        $this->assertTrue($journal['completed']);
        $this->assertSame(5, DB::connection('transfer_target')->table('users')->count());
        $this->assertSame(0, DB::connection('transfer_target')->table('users')->where('is_demo', true)->count());
        $this->assertSame($candidate, DB::connection('transfer_target')->table('applications')->where('id', 1)->value('candidate_id'));
        $this->assertSame('2026-01-01 00:00:00', DB::connection('transfer_target')->table('cv_documents')->where('id', 1)->value('archived_at'));
        $this->assertSame(DB::table('application_status_histories')->count(), DB::connection('transfer_target')->table('application_status_histories')->count());
        $this->assertSame('local', DB::table('cv_documents')->where('id', 1)->value('disk'));
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertSuccessful();
        Http::assertSentCount(13);
        $this->assertCount(7, Http::recorded(fn ($request): bool => $request->method() === 'POST'));
        $this->assertSame(5, DB::connection('transfer_target')->table('users')->count());
        $this->assertSame('local', config('supabase.cv_disk'));
    }

    public function test_preexisting_unowned_auth_email_stops_before_any_remote_write(): void
    {
        $this->prepareDatabases();
        Http::preventStrayRequests();
        Http::fake(['https://transfer.test/auth/v1/admin/users*' => Http::response(['users' => [['id' => (string) Str::uuid(), 'email' => 'candidate@example.test', 'app_metadata' => []]]])]);
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertFailed();
        Http::assertSentCount(1);
        $this->assertSame(0, DB::connection('transfer_target')->table('users')->count());
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_bad_pdf_readback_prevents_business_database_commit(): void
    {
        $this->prepareDatabases();
        $this->fakeCloud(true);
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(['status' => 'CLEAN', 'engine' => 'test', 'reason' => null]));
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertFailed();
        $this->assertSame(0, DB::connection('transfer_target')->table('users')->count());
        $this->assertSame(5, DB::table('users')->count());
        $this->assertFalse(json_decode(Storage::disk('local')->get('local-cloud-transfer.json'), true)['completed']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/object/authenticated/'));
    }

    #[DataProvider('blockedScanResults')]
    public function test_unsafe_or_unscannable_pdf_prevents_business_database_commit(string $status): void
    {
        $this->prepareDatabases();
        $this->fakeCloud();
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(['status' => $status, 'engine' => 'test', 'reason' => 'blocked']));

        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertFailed();

        $this->assertSame(0, DB::connection('transfer_target')->table('users')->count());
        $this->assertSame(5, DB::table('users')->count());
        $this->assertFalse(json_decode(Storage::disk('local')->get('local-cloud-transfer.json'), true)['completed']);
        $this->assertSame('local', config('supabase.cv_disk'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/object/authenticated/'));
    }

    public static function blockedScanResults(): array
    {
        return ['malware detected' => ['INFECTED'], 'scanner unavailable' => ['ERROR']];
    }

    public function test_nonempty_target_is_not_overwritten(): void
    {
        $this->prepareDatabases();
        DB::connection('transfer_target')->table('locations')->insert(['id' => 99, 'code' => 'EXTERNAL', 'name' => 'Existing external data']);
        Http::preventStrayRequests();
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertSame('Existing external data', DB::connection('transfer_target')->table('locations')->where('id', 99)->value('name'));
    }

    public function test_missing_source_pdf_prevents_auth_and_storage_writes(): void
    {
        $this->prepareDatabases();
        Storage::disk('local')->delete(DB::table('cv_documents')->where('id', 1)->value('storage_key'));
        Http::preventStrayRequests();
        $this->artisan('app:transfer-local-to-supabase', ['--execute' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertSame(0, DB::connection('transfer_target')->table('users')->count());
    }

    private function prepareDatabases(): void
    {
        Storage::fake('local');
        config(['supabase.url' => 'https://transfer.test', 'supabase.secret_key' => 'sb_secret_'.str_repeat('x', 30), 'supabase.storage_bucket' => 'internship-cvs']);
        foreach (['transfer_source', 'transfer_target'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
            DB::purge($name);
            Artisan::call('migrate', ['--database' => $name, '--force' => true]);
        }
        DB::setDefaultConnection('transfer_source');
        $this->seed(DemoSeeder::class);
        Artisan::registerCommand(app(TransferWithIsolatedDatabases::class));
    }

    private function fakeCloud(bool $corrupt = false): void
    {
        Http::preventStrayRequests();
        $identities = [];
        $objects = [];
        Http::fake(function ($request) use (&$identities, &$objects, $corrupt) {
            $this->assertFalse($request->hasHeader('Authorization'));
            if (str_contains($request->url(), '/auth/v1/admin/users')) {
                if ($request->method() === 'GET') {
                    return Http::response(['users' => array_values($identities)]);
                }
                $user = ['id' => (string) Str::uuid(), 'email' => $request['email'], 'app_metadata' => $request['app_metadata']];
                $identities[$user['email']] = $user;
                $this->assertTrue($request['email_confirm']);

                return Http::response($user);
            }
            if (str_contains($request->url(), '/storage/v1/object/internship-cvs/') && $request->method() === 'POST') {
                $objects[basename($request->url())] = $request->body();

                return Http::response(['Key' => basename($request->url())]);
            }
            if (str_contains($request->url(), '/storage/v1/object/authenticated/internship-cvs/') && $request->method() === 'GET') {
                return Http::response($corrupt ? '%PDF-wrong' : $objects[basename($request->url())], 200, ['Content-Type' => 'application/pdf']);
            }
            throw new \RuntimeException('Unexpected fake endpoint');
        });
    }
}

class TransferWithIsolatedDatabases extends TransferLocalToSupabase
{
    protected function assertTargets(): void {}

    protected function source(): Connection
    {
        return DB::connection('transfer_source');
    }

    protected function target(): Connection
    {
        return DB::connection('transfer_target');
    }
}
