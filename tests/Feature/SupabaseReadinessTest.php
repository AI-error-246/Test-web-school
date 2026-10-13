<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseReadinessTest extends TestCase
{
    public function test_missing_cloud_credentials_fail_before_any_remote_call(): void
    {
        config(['database.connections.supabase.url' => null, 'supabase.secret_key' => null]);
        Http::preventStrayRequests();
        $this->artisan('app:supabase-readiness')->expectsOutputToContain('Thiếu SUPABASE_DB_URL')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_wrong_project_is_rejected_without_contacting_it(): void
    {
        config(['database.connections.supabase.url' => 'postgresql://postgres.other:private@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres', 'supabase.secret_key' => 'sb_secret_test', 'supabase.url' => 'https://xwkfsxohvpebnaqqhuxz.supabase.co']);
        Http::preventStrayRequests();
        $this->artisan('app:supabase-readiness', ['--prepare' => true])->expectsOutputToContain('Kết nối phải thuộc đúng project')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_cloud_connection_error_does_not_print_credentials(): void
    {
        config(['database.connections.supabase.url' => 'postgresql://postgres.xwkfsxohvpebnaqqhuxz:private-password@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres', 'supabase.secret_key' => 'sb_secret_private', 'supabase.url' => 'https://xwkfsxohvpebnaqqhuxz.supabase.co', 'supabase.storage_bucket' => 'internship-cvs']);
        DB::shouldReceive('connection')->once()->with('supabase')->andThrow(new \RuntimeException('private-password sb_secret_private'));
        Http::preventStrayRequests();
        $this->artisan('app:supabase-readiness')->expectsOutputToContain('Supabase chưa xác minh được ở bước: kết nối database')->doesntExpectOutputToContain('private-password')->doesntExpectOutputToContain('sb_secret_private')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
