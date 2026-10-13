<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\CvDocument;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CandidateLibraryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_saved_jobs_are_idempotent_owned_and_remove_does_not_touch_another_candidate(): void
    {
        Http::preventStrayRequests();
        $candidate = User::factory()->candidate()->create();
        $other = User::factory()->candidate()->create();
        $job = JobPosting::factory()->accepting()->create(['title' => 'Saved opportunity']);
        $this->actingAs($other)->post('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->actingAs($candidate)->get('/candidate/saved-jobs')->assertOk()->assertDontSee('Saved opportunity');
        $this->post('/candidate/saved-jobs/'.$job->id, ['candidate_id' => $other->id])->assertRedirect();
        $this->post('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->assertDatabaseCount('candidate_saved_jobs', 2);
        $this->get('/candidate/saved-jobs')->assertOk()->assertSee('Saved opportunity');
        $this->delete('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->delete('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->assertDatabaseHas('candidate_saved_jobs', ['candidate_id' => $other->id, 'job_id' => $job->id]);
        $this->assertDatabaseMissing('candidate_saved_jobs', ['candidate_id' => $candidate->id, 'job_id' => $job->id]);
        Http::assertNothingSent();
    }

    public function test_nonpublic_jobs_are_rejected_and_previous_library_entries_disappear(): void
    {
        $candidate = User::factory()->candidate()->create();
        $job = JobPosting::factory()->accepting()->create(['title' => 'Private hidden vacancy']);
        $this->actingAs($candidate)->post('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->post('/candidate/job-history/'.$job->id)->assertNoContent();
        $job->update(['moderation_state' => 'HIDDEN']);
        $this->get('/candidate/saved-jobs')->assertOk()->assertDontSee('Private hidden vacancy');
        $this->get('/candidate/job-history')->assertOk()->assertDontSee('Private hidden vacancy');
        $this->post('/candidate/saved-jobs/'.$job->id)->assertNotFound();
        $this->post('/candidate/job-history/'.$job->id)->assertNotFound();
        $job->update(['moderation_state' => 'APPROVED']);
        $job->company->update(['visibility' => 'HIDDEN']);
        $this->get('/candidate/saved-jobs')->assertOk()->assertDontSee('Private hidden vacancy');
        $this->post('/candidate/job-history/'.$job->id)->assertNotFound();
        $job->company->update(['visibility' => 'VISIBLE']);
        $job->company->owner->update(['status' => 'BLOCKED']);
        $this->get('/candidate/job-history')->assertOk()->assertDontSee('Private hidden vacancy');
        $this->post('/candidate/saved-jobs/'.$job->id)->assertNotFound();
        $this->delete('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->assertDatabaseCount('candidate_saved_jobs', 0);
    }

    public function test_history_updates_recency_caps_at_fifty_and_clears_only_owner(): void
    {
        $this->freezeTime();
        $candidate = User::factory()->candidate()->create();
        $other = User::factory()->candidate()->create();
        $jobs = JobPosting::factory()->accepting()->count(51)->create();
        foreach ($jobs->take(50) as $job) {
            DB::table('candidate_job_views')->insert(['candidate_id' => $candidate->id, 'job_id' => $job->id, 'viewed_at' => now()->subMinutes(2)]);
        }
        DB::table('candidate_job_views')->insert(['candidate_id' => $other->id, 'job_id' => $jobs->first()->id, 'viewed_at' => now()]);
        $this->actingAs($candidate)->post('/candidate/job-history/'.$jobs->first()->id)->assertNoContent();
        $this->travel(1)->minutes();
        $this->post('/candidate/job-history/'.$jobs->last()->id)->assertNoContent();
        $this->assertSame(50, DB::table('candidate_job_views')->where('candidate_id', $candidate->id)->count());
        $this->assertDatabaseHas('candidate_job_views', ['candidate_id' => $candidate->id, 'job_id' => $jobs->first()->id]);
        $this->assertDatabaseMissing('candidate_job_views', ['candidate_id' => $candidate->id, 'job_id' => $jobs[1]->id]);
        $this->get('/candidate/job-history')->assertOk()->assertViewHas('jobs', fn ($page): bool => $page->first()->id === $jobs->last()->id);
        $this->delete('/candidate/job-history')->assertRedirect();
        $this->assertDatabaseCount('candidate_job_views', 1);
        $this->assertDatabaseHas('candidate_job_views', ['candidate_id' => $other->id]);
    }

    public function test_guest_employer_and_blocked_candidate_cannot_use_candidate_library(): void
    {
        $job = JobPosting::factory()->accepting()->create();
        $this->get('/candidate/saved-jobs')->assertRedirect('/login');
        $this->post('/candidate/job-history/'.$job->id)->assertRedirect('/login');
        $this->actingAs(User::factory()->employer()->create());
        $this->get('/candidate/job-history')->assertForbidden();
        $this->post('/candidate/saved-jobs/'.$job->id)->assertForbidden();
        $this->delete('/candidate/job-history')->assertForbidden();
        $this->actingAs(User::factory()->candidate()->create(['status' => 'BLOCKED']));
        $this->post('/candidate/saved-jobs/'.$job->id)->assertRedirect('/login');
        $this->assertDatabaseCount('candidate_saved_jobs', 0);
        $this->assertDatabaseCount('candidate_job_views', 0);
    }

    public function test_profile_completeness_counts_only_own_active_cv_and_updates_after_profile_edit(): void
    {
        $candidate = User::factory()->candidate()->create();
        CandidateProfile::factory()->create(['user_id' => $candidate->id, 'full_name' => 'Linh', 'phone' => null, 'university' => null, 'major' => null, 'study_year' => null, 'introduction' => null, 'skills_text' => null]);
        CvDocument::factory()->create();
        $this->actingAs($candidate)->get('/candidate/profile')->assertOk()->assertViewHas('completeness', fn ($score): bool => $score['completed'] === 1 && $score['percent'] === 13);
        $this->put('/candidate/profile', ['full_name' => 'Linh', 'phone' => '0900000000', 'university' => 'Đại học', 'major' => 'CNTT', 'study_year' => 3, 'introduction' => 'Sinh viên', 'skills_text' => 'Laravel'])->assertRedirect();
        $cv = CvDocument::factory()->create(['candidate_id' => $candidate->id, 'archived_at' => now()]);
        $this->get('/candidate')->assertOk()->assertViewHas('completeness', fn ($score): bool => $score['completed'] === 7 && $score['percent'] === 88);
        $cv->update(['archived_at' => null]);
        $this->get('/candidate/profile')->assertOk()->assertViewHas('completeness', fn ($score): bool => $score['percent'] === 100 && $score['missing'] === []);
    }

    public function test_library_escapes_job_titles_and_get_detail_does_not_write_history(): void
    {
        $candidate = User::factory()->candidate()->create();
        $job = JobPosting::factory()->closed()->create(['title' => '<script>alert(1)</script>']);
        $this->get('/jobs/'.$job->id)->assertOk()->assertDontSee('data-history-message', false);
        $this->actingAs($candidate)->get('/jobs/'.$job->id)->assertOk()->assertSee('Lưu việc yêu thích')->assertSee('data-history-message', false);
        $this->assertDatabaseCount('candidate_job_views', 0);
        $this->post('/candidate/saved-jobs/'.$job->id)->assertRedirect();
        $this->get('/jobs/'.$job->id)->assertOk()->assertSee('Bỏ lưu việc này');
        $this->get('/candidate/saved-jobs')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('Đã ngừng nhận hồ sơ');
    }
}
