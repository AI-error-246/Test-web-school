<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobWorkflow;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployerWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_open_employer_workspace(): void
    {
        $this->get('/employer')->assertRedirectToRoute('login');
    }

    #[DataProvider('otherRoles')]
    public function test_non_employer_cannot_create_jobs(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_demo' => true]);
        if ($role === 'admin') {
            $this->withSession($this->adminMfaSession($user));
        }

        $this->actingAs($user)->post('/employer/jobs', ['title' => 'Thực tập sinh', 'work_mode' => 'ONSITE'])->assertForbidden();

        $this->assertDatabaseCount('job_postings', 0);
    }

    public static function otherRoles(): array
    {
        return ['candidate' => ['candidate'], 'admin' => ['admin']];
    }

    public function test_creates_draft_without_accepting_owner_or_approval_from_request(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($company->owner)->post('/employer/jobs', ['title' => 'Thực tập sinh PHP', 'work_mode' => 'HYBRID', 'company_id' => 999, 'moderation_state' => 'APPROVED', 'publication_state' => 'OPEN'])->assertRedirect();

        $this->assertDatabaseHas('job_postings', ['title' => 'Thực tập sinh PHP', 'company_id' => $company->id, 'publication_state' => 'DRAFT', 'moderation_state' => 'NOT_SUBMITTED', 'version' => 1]);
    }

    public function test_complete_draft_can_be_submitted_but_is_not_published(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->create(['publication_state' => 'DRAFT', 'moderation_state' => 'NOT_SUBMITTED', 'published_at' => null]);

        $this->actingAs($job->company->owner)->post("/employer/jobs/{$job->id}/submit", ['version' => 1])->assertRedirectToRoute('employer.jobs');

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'publication_state' => 'DRAFT', 'moderation_state' => 'PENDING', 'published_at' => null]);
    }

    public function test_incomplete_draft_stays_private_when_submission_fails(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->create(['publication_state' => 'DRAFT', 'moderation_state' => 'NOT_SUBMITTED', 'description' => null, 'deadline_at' => now()->subMinute()]);

        $this->actingAs($job->company->owner)->post("/employer/jobs/{$job->id}/submit", ['version' => 1])->assertSessionHasErrors(['description', 'deadline_at']);

        $this->assertSame('NOT_SUBMITTED', $job->fresh()->moderation_state);
    }

    public function test_editing_an_open_job_increases_version_and_requires_review(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->create(['publication_state' => 'OPEN', 'moderation_state' => 'APPROVED']);

        $this->actingAs($job->company->owner)->put("/employer/jobs/{$job->id}", ['version' => 1, 'title' => 'Nội dung đã sửa', 'work_mode' => 'ONSITE'])->assertRedirectToRoute('employer.jobs.edit', $job);

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'title' => 'Nội dung đã sửa', 'version' => 2, 'moderation_state' => 'PENDING', 'publication_state' => 'OPEN']);
    }

    public function test_stale_job_version_returns_conflict_without_losing_content(): void
    {
        $job = JobPosting::factory()->create(['version' => 2]);

        $this->actingAs($job->company->owner)->put("/employer/jobs/{$job->id}", ['version' => 1, 'title' => 'Ghi đè không hợp lệ', 'work_mode' => 'ONSITE'])->assertConflict();

        $this->assertSame($job->title, $job->fresh()->title);
    }

    #[DataProvider('lockedJobStates')]
    public function test_closed_or_hidden_jobs_cannot_be_edited(string $publication, string $moderation): void
    {
        $job = JobPosting::factory()->create(['publication_state' => $publication, 'moderation_state' => $moderation]);

        $this->actingAs($job->company->owner)->put("/employer/jobs/{$job->id}", ['version' => 1, 'title' => 'Bypass', 'work_mode' => 'ONSITE'])->assertSessionHasErrors('job');

        $this->assertSame($job->title, $job->fresh()->title);
    }

    public static function lockedJobStates(): array
    {
        return ['closed' => ['CLOSED', 'APPROVED'], 'hidden' => ['OPEN', 'HIDDEN']];
    }

    public function test_other_employer_cannot_read_or_modify_job_or_applicants(): void
    {
        $job = JobPosting::factory()->create();
        $other = User::factory()->create(['role' => 'employer', 'is_demo' => true]);

        $this->actingAs($other)->get("/employer/jobs/{$job->id}/edit")->assertNotFound();
        $this->get("/employer/jobs/{$job->id}/applications")->assertNotFound();
        $this->put("/employer/jobs/{$job->id}", ['version' => 1, 'title' => 'Other tenant', 'work_mode' => 'ONSITE'])->assertNotFound();
        $this->post("/employer/jobs/{$job->id}/close", ['version' => 1])->assertNotFound();

        $this->assertSame($job->title, $job->fresh()->title);
    }

    public function test_closing_a_pending_open_job_keeps_applications_and_review_state(): void
    {
        $application = JobApplication::factory()->create();
        $job = $application->job;
        $job->update(['publication_state' => 'OPEN', 'moderation_state' => 'PENDING']);

        $this->actingAs($job->company->owner)->post("/employer/jobs/{$job->id}/close", ['version' => $job->version])->assertRedirectToRoute('employer.jobs');

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'publication_state' => 'CLOSED', 'moderation_state' => 'PENDING']);
        $this->assertModelExists($application);
    }

    public function test_application_status_and_history_are_saved_on_closed_job(): void
    {
        $this->freezeTime();
        $application = JobApplication::factory()->create(['status' => 'SUBMITTED', 'version' => 1]);
        $application->job->update(['publication_state' => 'CLOSED']);
        $owner = $application->job->company->owner;

        $this->actingAs($owner)->patch("/employer/applications/{$application->id}", ['version' => 1, 'status' => 'REVIEWING'])->assertRedirectToRoute('applications.show', $application);

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'REVIEWING', 'version' => 2]);
        $this->assertDatabaseHas('application_status_histories', ['application_id' => $application->id, 'changed_by' => $owner->id, 'from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING']);
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_application_transition_has_no_history_side_effect(string $from, string $to): void
    {
        $application = JobApplication::factory()->create(['status' => $from, 'version' => 1]);
        $historyCount = $application->history()->count();

        $this->actingAs($application->job->company->owner)->patch("/employer/applications/{$application->id}", ['version' => 1, 'status' => $to])->assertSessionHasErrors('status');

        $this->assertSame($from, $application->fresh()->status);
        $this->assertSame($historyCount, $application->history()->count());
    }

    public static function invalidTransitions(): array
    {
        return ['skip review' => ['SUBMITTED', 'SELECTED'], 'selected terminal' => ['SELECTED', 'REJECTED'], 'rejected terminal' => ['REJECTED', 'REVIEWING']];
    }

    public function test_stale_application_version_returns_conflict_without_history(): void
    {
        $application = JobApplication::factory()->create(['status' => 'REVIEWING', 'version' => 2]);
        $historyCount = $application->history()->count();

        $this->actingAs($application->job->company->owner)->patch("/employer/applications/{$application->id}", ['version' => 1, 'status' => 'REJECTED'])->assertConflict();

        $this->assertSame('REVIEWING', $application->fresh()->status);
        $this->assertSame($historyCount, $application->history()->count());
    }

    public function test_other_employer_cannot_change_application_status(): void
    {
        $application = JobApplication::factory()->create(['status' => 'SUBMITTED']);
        $other = User::factory()->create(['role' => 'employer', 'is_demo' => true]);

        $this->actingAs($other)->patch("/employer/applications/{$application->id}", ['version' => 1, 'status' => 'REJECTED'])->assertNotFound();

        $this->assertSame('SUBMITTED', $application->fresh()->status);
    }

    public function test_hidden_company_cannot_be_made_visible_by_profile_update(): void
    {
        $company = Company::factory()->create(['visibility' => 'HIDDEN']);

        $this->actingAs($company->owner)->put('/employer/company', ['name' => 'Tên mới', 'description' => 'Công ty tuyển thực tập sinh', 'address' => 'Hà Nội', 'contact_email' => 'hr@example.test', 'visibility' => 'VISIBLE', 'owner_user_id' => 'invalid'])->assertRedirectToRoute('employer.company');

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Tên mới', 'visibility' => 'HIDDEN', 'owner_user_id' => $company->owner_user_id]);
    }

    public function test_rejects_svg_logo_and_creates_no_uploaded_file(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($company->owner)->put('/employer/company', ['name' => $company->name, 'description' => 'Description', 'address' => 'Hà Nội', 'contact_email' => 'hr@example.test', 'logo' => $file])->assertSessionHasErrors('logo');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_uploads_raster_logo_with_generated_storage_name(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=');
        $file = UploadedFile::fake()->createWithContent('business.png', $png);

        $this->actingAs($company->owner)->put('/employer/company', ['name' => $company->name, 'description' => 'Công ty công nghệ', 'address' => 'Hà Nội', 'contact_email' => 'hr@example.test', 'logo' => $file])->assertRedirectToRoute('employer.company');

        $key = $company->fresh()->logo_key;
        $this->assertStringStartsWith('companies/', $key);
        $this->assertStringNotContainsString('business.png', $key);
        Storage::disk('public')->assertExists($key);
    }

    public function test_invalid_allowance_range_does_not_create_draft(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($company->owner)->post('/employer/jobs', ['title' => 'Thực tập sinh', 'work_mode' => 'ONSITE', 'allowance_min' => 4000000, 'allowance_max' => 2000000])->assertSessionHasErrors(['allowance_max' => 'Trợ cấp tối đa phải lớn hơn hoặc bằng trợ cấp tối thiểu.']);

        $this->assertDatabaseCount('job_postings', 0);
    }

    public function test_hidden_company_cannot_submit_job(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->create();
        $job->company->update(['visibility' => 'HIDDEN']);

        $this->actingAs($job->company->owner)->post("/employer/jobs/{$job->id}/submit", ['version' => 1])->assertSessionHasErrors('company');

        $this->assertSame('NOT_SUBMITTED', $job->fresh()->moderation_state);
    }

    public function test_employer_view_escapes_job_title(): void
    {
        $job = JobPosting::factory()->create(['title' => '<script>alert("unsafe")</script>']);

        $this->actingAs($job->company->owner)->get('/employer/jobs')->assertSee($job->title)->assertDontSee($job->title, false);
    }

    public function test_owner_can_open_company_job_editor_dashboard_and_applicants(): void
    {
        $job = JobPosting::factory()->create();
        $this->actingAs($job->company->owner);

        $this->get('/employer')->assertSee('Không gian tuyển dụng');
        $this->get('/employer/company')->assertSee('Hồ sơ công ty');
        $this->get('/employer/jobs/create')->assertSee('Lưu bản nháp');
        $this->get("/employer/jobs/{$job->id}/edit")->assertSee('Xem trước nội dung đã lưu');
        $this->get("/employer/jobs/{$job->id}/applications")->assertSee('Hồ sơ theo vị trí');
    }

    public function test_workflow_rechecks_account_status_before_receiving_application(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->create(['publication_state' => 'OPEN', 'moderation_state' => 'APPROVED']);
        $job->company->owner->update(['status' => 'BLOCKED']);

        $this->expectException(ValidationException::class);

        DB::transaction(function () use ($job): void {
            $workflow = app(JobWorkflow::class);
            $workflow->assertCanReceive($workflow->lockJob($job->id));
        });
    }
}
