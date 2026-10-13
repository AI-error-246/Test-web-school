<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployerProductivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_clear_and_detect_stale_notes_without_changing_application(): void
    {
        $application = JobApplication::factory()->create();
        $owner = $application->job->company->owner;
        $url = route('employer.applications.note', $application);
        $this->actingAs($owner)->put($url, ['body' => '<script>private</script>', 'version' => 0, 'employer_id' => $application->candidate_id])->assertRedirectToRoute('applications.show', $application);
        $this->assertDatabaseHas('employer_application_notes', ['application_id' => $application->id, 'employer_id' => $owner->id, 'body' => '<script>private</script>', 'version' => 1]);
        $this->get(route('applications.show', $application))->assertOk()->assertSee('<script>private</script>')->assertDontSee('<script>private</script>', false);
        $this->put($url, ['body' => 'Stale initial form', 'version' => 0])->assertConflict();
        $this->put($url, ['body' => '', 'version' => 1])->assertRedirect();
        $this->assertDatabaseHas('employer_application_notes', ['application_id' => $application->id, 'body' => '', 'version' => 2]);
        $this->put($url, ['body' => 'Stale edit', 'version' => 1])->assertConflict();
        $this->assertSame(1, $application->fresh()->version);
        $this->assertSame('SUBMITTED', $application->fresh()->status);
    }

    public function test_notes_reject_guests_candidates_foreign_owners_and_invalid_input(): void
    {
        $application = JobApplication::factory()->create();
        $url = route('employer.applications.note', $application);
        $this->put($url, ['body' => 'Private', 'version' => 0])->assertRedirectToRoute('login');
        $this->actingAs($application->candidate)->put($url, ['body' => 'Private', 'version' => 0])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'employer']))->put($url, ['body' => 'Private', 'version' => 0])->assertNotFound();
        $this->actingAs($application->job->company->owner)->put($url, ['body' => str_repeat('x', 3001), 'version' => 0])->assertSessionHasErrors('body');
        $this->put($url, ['body' => 'Private'])->assertSessionHasErrors('version');
        $this->assertDatabaseCount('employer_application_notes', 0);
    }

    public function test_candidate_cannot_see_employer_note(): void
    {
        $application = JobApplication::factory()->create();
        $this->actingAs($application->job->company->owner)->put(route('employer.applications.note', $application), ['body' => 'Owner confidential remark', 'version' => 0])->assertRedirect();
        $this->actingAs($application->candidate)->get(route('applications.show', $application))->assertOk()->assertDontSee('Owner confidential remark')->assertDontSee('Ghi chú riêng của nhà tuyển dụng');
    }

    public function test_filters_use_vietnam_day_boundaries_current_status_keywords_and_owner_scope(): void
    {
        $first = JobApplication::factory()->create(['name_snapshot' => 'Matching Person', 'applied_at' => '2026-10-02 17:00:00', 'status' => 'REVIEWING']);
        $job = $first->job;
        $last = JobApplication::factory()->create(['job_id' => $job->id, 'name_snapshot' => 'Matching Last', 'applied_at' => '2026-10-03 16:59:59', 'status' => 'REVIEWING']);
        JobApplication::factory()->create(['job_id' => $job->id, 'name_snapshot' => 'Matching Yesterday', 'applied_at' => '2026-10-02 16:59:59']);
        JobApplication::factory()->create(['job_id' => $job->id, 'name_snapshot' => 'Matching Tomorrow', 'applied_at' => '2026-10-03 17:00:00']);
        JobApplication::factory()->create(['name_snapshot' => 'Matching Foreign', 'applied_at' => '2026-10-03 01:00:00']);
        $url = route('employer.applications', $job);
        $this->actingAs($job->company->owner)->get($url.'?from=2026-10-03&to=2026-10-03&q=matching&status=REVIEWING&sort=oldest')
            ->assertOk()->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $last->id]);
        $this->get($url.'?from=2026-10-03&to=2026-10-03')->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$last->id, $first->id]);
        $this->get('/employer?from=2026-10-03&to=2026-10-03')->assertOk()->assertViewHas('applicationCount', 2)->assertViewHas('statusCounts', fn ($counts) => (int) $counts->get('REVIEWING') === 2);
        $this->get($url.'?to=2026-10-02')->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        $this->get($url.'?from=2026-10-04&to=2026-10-03')->assertSessionHasErrors('to');
        $this->get($url.'?sort=unsafe')->assertSessionHasErrors('sort');
    }

    public function test_keyword_search_email_university_and_wildcards_are_literal(): void
    {
        $application = JobApplication::factory()->create(['email_snapshot' => 'special@example.test', 'university_snapshot' => 'Campus 100%']);
        JobApplication::factory()->create(['job_id' => $application->job_id, 'university_snapshot' => 'Campus 1000']);
        $this->actingAs($application->job->company->owner);
        foreach (['special@example.test', '100%'] as $keyword) {
            $this->get(route('employer.applications', $application->job).'?'.http_build_query(['q' => $keyword]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->pluck('id')->all() === [$application->id]);
        }
        $this->get(route('employer.applications', $application->job).'?'.http_build_query(['q' => "' OR 1=1 --"]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 0);
    }

    public function test_dashboard_without_company_has_zero_counts(): void
    {
        JobApplication::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'employer']))->get('/employer')->assertOk()->assertViewHas('applicationCount', 0)->assertViewHas('newCount', 0);

    }
}
