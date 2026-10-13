<?php

namespace Tests\Feature;

use App\Models\ApplicationStatusHistory;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_candidate_account_and_profile_use_the_supabase_uuid_without_password_storage(): void
    {
        $profile = CandidateProfile::factory()->create();
        $cv = CvDocument::factory()->create(['candidate_id' => $profile->user_id]);

        $this->assertSame($profile->user_id, $profile->user->id);
        $this->assertSame($profile->user_id, $cv->candidate->id);
        $this->assertSame($cv->id, $profile->cvs->sole()->id);
        $this->assertFalse(Schema::hasColumn('users', 'password'));
    }

    public function test_application_relationships_and_snapshot_survive_profile_edits(): void
    {
        $this->freezeTime();
        $application = JobApplication::factory()->create(['name_snapshot' => 'Tên tại thời điểm nộp']);
        $history = ApplicationStatusHistory::factory()->create(['application_id' => $application->id]);
        $application->candidate->candidateProfile->update(['full_name' => 'Tên mới']);

        $this->assertSame('Tên tại thời điểm nộp', $application->fresh()->name_snapshot);
        $this->assertSame($application->candidate_id, $application->cv->candidate_id);
        $this->assertSame($history->id, $application->history->sole()->id);
        $this->assertSame($application->candidate_id, $history->actor->id);
        $this->assertSame(now()->toDateTimeString(), $application->applied_at->toDateTimeString());
    }

    public function test_candidate_cannot_have_two_applications_for_the_same_job(): void
    {
        $application = JobApplication::factory()->create();
        $this->expectException(QueryException::class);

        JobApplication::factory()->create([
            'candidate_id' => $application->candidate_id, 'job_id' => $application->job_id, 'cv_id' => $application->cv_id,
        ]);
    }

    public function test_database_rejects_an_application_using_another_candidates_cv(): void
    {
        $candidate = CandidateProfile::factory()->create();
        $otherCv = CvDocument::factory()->create();
        $this->expectException(QueryException::class);

        JobApplication::factory()->create(['candidate_id' => $candidate->user_id, 'cv_id' => $otherCv->id]);
    }

    public function test_used_cv_cannot_be_deleted(): void
    {
        $application = JobApplication::factory()->create();
        $this->expectException(QueryException::class);

        $application->cv->delete();
    }

    public function test_employer_can_own_only_one_company(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);

        Company::factory()->create(['owner_user_id' => $company->owner_user_id]);
    }

    /** @return array<string, array{string, string, string, string, bool}> */
    public static function visibilityStates(): array
    {
        return [
            'open approved' => ['OPEN', 'APPROVED', 'VISIBLE', 'ACTIVE', true],
            'closed approved remains visible' => ['CLOSED', 'APPROVED', 'VISIBLE', 'ACTIVE', true],
            'draft excluded' => ['DRAFT', 'APPROVED', 'VISIBLE', 'ACTIVE', false],
            'pending excluded' => ['OPEN', 'PENDING', 'VISIBLE', 'ACTIVE', false],
            'hidden excluded' => ['OPEN', 'HIDDEN', 'VISIBLE', 'ACTIVE', false],
            'hidden company excluded' => ['OPEN', 'APPROVED', 'HIDDEN', 'ACTIVE', false],
            'blocked employer excluded' => ['OPEN', 'APPROVED', 'VISIBLE', 'BLOCKED', false],
        ];
    }

    #[DataProvider('visibilityStates')]
    public function test_public_scope_applies_all_visibility_conditions(string $publication, string $moderation, string $visibility, string $status, bool $expected): void
    {
        $job = JobPosting::factory()->create(['publication_state' => $publication, 'moderation_state' => $moderation]);
        $job->company->update(['visibility' => $visibility]);
        $job->company->owner->update(['status' => $status]);

        $this->assertSame($expected, JobPosting::publiclyVisible()->whereKey($job->id)->exists());
    }

    public function test_accepting_scope_excludes_closed_and_deadline_boundary(): void
    {
        $this->freezeTime();
        $open = JobPosting::factory()->accepting()->create();
        JobPosting::factory()->closed()->create();
        JobPosting::factory()->accepting()->create(['deadline_at' => now()]);

        $this->assertSame([$open->id], JobPosting::accepting()->pluck('id')->all());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidAllowances(): array
    {
        return [
            'negative minimum' => [['allowance_min' => -1]],
            'negative maximum' => [['allowance_max' => -1]],
            'reversed bounds' => [['allowance_min' => 5000000, 'allowance_max' => 2000000]],
            'missing currency' => [['currency' => null]],
        ];
    }

    #[DataProvider('invalidAllowances')]
    public function test_postgresql_rejects_invalid_allowance_values(array $attributes): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        JobPosting::factory()->create($attributes);
    }

    public function test_postgresql_moderation_requires_exactly_one_target(): void
    {
        $this->requirePostgres();
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);

        ModerationAction::factory()->create(['company_id' => $company->id]);
    }

    public function test_postgresql_completed_report_requires_resolution_evidence(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        Report::factory()->create(['status' => 'RESOLVED']);
    }

    /** @return array<string, array{int}> */
    public static function invalidCvSizes(): array
    {
        return ['empty document' => [0], 'over five megabytes' => [5242881]];
    }

    #[DataProvider('invalidCvSizes')]
    public function test_postgresql_rejects_invalid_cv_size(int $size): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        CvDocument::factory()->create(['size_bytes' => $size]);
    }

    public function test_postgresql_moderation_rejects_a_missing_target(): void
    {
        $this->requirePostgres();
        $this->expectException(QueryException::class);

        ModerationAction::factory()->create(['job_id' => null, 'company_id' => null]);
    }

    public function test_demo_seed_refuses_to_reuse_a_real_user_uuid(): void
    {
        User::factory()->candidate()->create(['id' => '11111111-1111-4111-8111-111111111111', 'is_demo' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UUID demo đã thuộc tài khoản thật');

        app(DemoSeeder::class)->run();
    }

    public function test_demo_seed_is_idempotent_and_stores_pdf_on_private_disk(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $application = JobApplication::firstOrFail();
        $application->update(['status' => 'REJECTED']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, User::count());
        $this->assertSame(10, JobPosting::count());
        $this->assertSame(8, JobPosting::accepting()->count());
        $this->assertSame(3, JobApplication::count());
        $this->assertSame(6, ApplicationStatusHistory::count());
        $this->assertSame(1, Report::count());
        $this->assertSame('REJECTED', $application->fresh()->status);
        foreach (CvDocument::all() as $cv) {
            Storage::disk('local')->assertExists($cv->storage_key);
            $this->assertStringStartsWith('%PDF-1.4', Storage::disk('local')->get($cv->storage_key));
        }
    }

    public function test_demo_seed_refuses_production_environment(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dữ liệu demo chỉ được seed');

        app(DemoSeeder::class)->run();
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL CHECK constraints require the isolated PostgreSQL test database.');
        }
    }
}
