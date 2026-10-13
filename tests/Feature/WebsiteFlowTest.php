<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\CvMalwareScanner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_search_combines_filters_and_excludes_hidden_jobs(): void
    {
        $this->freezeTime();
        $job = JobPosting::factory()->accepting()->create(['title' => 'Thực tập Laravel']);
        JobPosting::factory()->accepting()->create(['title' => 'Tin bí mật', 'moderation_state' => 'HIDDEN']);
        JobPosting::factory()->create(['title' => 'Bản nháp bí mật']);
        $this->get('/')->assertOk()->assertSee('Thực tập Laravel')->assertDontSee('Tin bí mật')->assertDontSee('Bản nháp bí mật');
        $this->get(route('jobs.index', ['q' => 'Laravel', 'category' => $job->category_id, 'location' => $job->location_id, 'mode' => $job->work_mode, 'sort' => 'deadline']))->assertOk()->assertSee($job->title);
        $this->get(route('jobs.index', ['q' => 'unmatched-term']))->assertOk()->assertSee('Chưa tìm thấy kết quả')->assertDontSee($job->title);
        $this->get(route('companies.show', $job->company))->assertOk()->assertSee($job->company->name);
    }

    public function test_hidden_detail_and_sql_sort_payload_are_rejected(): void
    {
        $job = JobPosting::factory()->accepting()->create(['moderation_state' => 'HIDDEN']);
        $this->get(route('jobs.show', $job))->assertNotFound();
        $this->getJson(route('jobs.index', ['sort' => 'title;DROP TABLE users']))->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_public_text_is_escaped(): void
    {
        $job = JobPosting::factory()->accepting()->create(['title' => '<script>alert(1)</script>']);
        $this->get(route('jobs.show', $job))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_candidate_can_save_profile_and_render_all_candidate_pages(): void
    {
        $profile = CandidateProfile::factory()->create();
        $user = $profile->user;
        $this->actingAs($user)->put(route('candidate.profile.update'), ['full_name' => 'Nguyễn An', 'phone' => '0912345678', 'university' => 'Đại học A', 'major' => 'CNTT', 'study_year' => 3])->assertRedirect();
        $this->assertDatabaseHas('candidate_profiles', ['user_id' => $user->id, 'full_name' => 'Nguyễn An']);
        foreach (['candidate.dashboard', 'candidate.profile', 'candidate.cvs', 'candidate.applications'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_guest_is_redirected_and_employer_cannot_edit_candidate_profile(): void
    {
        $this->get(route('candidate.profile'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->employer()->create())->get(route('candidate.profile'))->assertForbidden();
    }

    public function test_pdf_upload_rename_archive_keeps_private_file(): void
    {
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(['status' => 'CLEAN', 'engine' => 'test', 'reason' => null]));
        Storage::fake('local');
        $profile = CandidateProfile::factory()->create();
        $file = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF");
        $this->actingAs($profile->user)->post(route('candidate.cvs.store'), ['display_name' => 'CV đầu tiên', 'cv' => $file])->assertRedirect();
        $cv = CvDocument::sole();
        Storage::disk('local')->assertExists($cv->storage_key);
        $this->get(route('cvs.show', $cv))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->patch(route('candidate.cvs.update', $cv), ['display_name' => 'CV mới'])->assertRedirect();
        $this->post(route('candidate.cvs.archive', $cv))->assertRedirect();
        $this->assertNotNull($cv->fresh()->archived_at);
        Storage::disk('local')->assertExists($cv->storage_key);
        $this->assertSame('CV mới', $cv->fresh()->display_name);
    }

    public function test_cv_type_size_and_quota_are_validated(): void
    {
        Storage::fake('local');
        $profile = CandidateProfile::factory()->create();
        $this->actingAs($profile->user)->post(route('candidate.cvs.store'), ['display_name' => 'wrong', 'cv' => UploadedFile::fake()->createWithContent('cv.pdf', 'not a PDF')])->assertSessionHasErrors('cv');
        $this->post(route('candidate.cvs.store'), ['display_name' => 'large', 'cv' => UploadedFile::fake()->create('cv.pdf', 6000, 'application/pdf')])->assertSessionHasErrors('cv');
        CvDocument::factory()->count(5)->create(['candidate_id' => $profile->user_id]);
        $this->post(route('candidate.cvs.store'), ['display_name' => 'sixth', 'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasErrors('cv');
        $this->assertDatabaseCount('cv_documents', 5);
    }

    public function test_application_is_idempotent_and_keeps_snapshot_and_history(): void
    {
        $this->freezeTime();
        $profile = CandidateProfile::factory()->create(['full_name' => 'Nguyễn Minh']);
        $cv = CvDocument::factory()->create(['candidate_id' => $profile->user_id]);
        $job = JobPosting::factory()->accepting()->create();
        $this->actingAs($profile->user)->get(route('candidate.apply', $job))->assertOk();
        $response = $this->post(route('candidate.apply.store', $job), ['cv_id' => $cv->id, 'consent' => 1]);
        $application = JobApplication::sole();
        $response->assertRedirect(route('applications.show', $application));
        $this->post(route('candidate.apply.store', $job), ['cv_id' => $cv->id, 'consent' => 1])->assertRedirect(route('applications.show', $application));
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('application_status_histories', 1);
        $profile->update(['full_name' => 'Tên đã đổi']);
        $this->get(route('applications.show', $application))->assertOk()->assertSee('Nguyễn Minh')->assertDontSee('Tên đã đổi');
        $this->assertSame('SUBMITTED', $application->status);
    }

    public function test_cannot_submit_other_candidates_cv_or_incomplete_profile(): void
    {
        $profile = CandidateProfile::factory()->create();
        $otherCv = CvDocument::factory()->create();
        $job = JobPosting::factory()->accepting()->create();
        $this->actingAs($profile->user)->post(route('candidate.apply.store', $job), ['cv_id' => $otherCv->id, 'consent' => 1])->assertSessionHasErrors('cv_id');
        $profile->update(['phone' => null]);
        $own = CvDocument::factory()->create(['candidate_id' => $profile->user_id]);
        $this->post(route('candidate.apply.store', $job), ['cv_id' => $own->id, 'consent' => 1])->assertSessionHasErrors('profile');
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_closed_expired_hidden_and_blocked_employer_jobs_reject_new_applications(): void
    {
        $this->freezeTime();
        $profile = CandidateProfile::factory()->create();
        $cv = CvDocument::factory()->create(['candidate_id' => $profile->user_id]);
        $this->actingAs($profile->user);
        foreach ([['publication_state' => 'CLOSED'], ['deadline_at' => now()], ['moderation_state' => 'HIDDEN']] as $changes) {
            $job = JobPosting::factory()->accepting()->create($changes);
            $this->post(route('candidate.apply.store', $job), ['cv_id' => $cv->id, 'consent' => 1])->assertSessionHasErrors();
        }
        $job = JobPosting::factory()->accepting()->create();
        $job->company->owner->update(['status' => 'BLOCKED']);
        $this->post(route('candidate.apply.store', $job), ['cv_id' => $cv->id, 'consent' => 1])->assertSessionHasErrors();
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_cv_and_application_are_isolated_by_candidate_and_company(): void
    {
        $this->mock(CvMalwareScanner::class, fn ($mock) => $mock->shouldReceive('scan')->once()->andReturn(['status' => 'CLEAN', 'engine' => 'test', 'reason' => null]));
        Storage::fake('local');
        $application = JobApplication::factory()->create();
        Storage::disk('local')->put($application->cv->storage_key, "%PDF-1.4\n%%EOF");
        $other = User::factory()->candidate()->create();
        $this->actingAs($other)->get(route('applications.show', $application))->assertNotFound();
        $this->get(route('cvs.show', $application->cv_id))->assertNotFound();
        $this->actingAs(User::factory()->employer()->create())->get(route('applications.show', $application))->assertNotFound();
        $this->get(route('cvs.show', $application->cv_id))->assertNotFound();
        $this->actingAs($application->job->company->owner)->get(route('applications.show', $application))->assertOk();
        $this->get(route('cvs.show', $application->cv_id))->assertOk();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession($this->adminMfaSession($admin))->get(route('cvs.show', $application->cv_id))->assertNotFound();
    }

    public function test_blocked_account_cannot_use_an_existing_session(): void
    {
        $user = User::factory()->candidate()->create();
        $this->actingAs($user);
        $user->update(['status' => 'BLOCKED']);
        $this->get(route('candidate.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
