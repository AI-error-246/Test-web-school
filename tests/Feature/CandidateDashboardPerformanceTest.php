<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CandidateDashboardPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_aggregates_only_owned_applications_without_loading_live_job_relations(): void
    {
        $application = JobApplication::factory()->create(['job_title_snapshot' => 'Original vacancy', 'company_name_snapshot' => 'Original company']);
        foreach (['REVIEWING', 'SELECTED', 'REJECTED'] as $status) {
            JobApplication::factory()->create(['candidate_id' => $application->candidate_id, 'cv_id' => $application->cv_id, 'status' => $status]);
        }
        JobApplication::factory()->create(['status' => 'SELECTED']);
        $application->job->update(['title' => 'Changed live vacancy', 'moderation_state' => 'HIDDEN']);
        $candidate = $application->candidate;
        $this->actingAs($candidate);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get('/candidate')->assertOk()->assertViewHas('total', 4)
            ->assertViewHas('reviewing', 1)->assertViewHas('selected', 1)
            ->assertSee('Original vacancy')->assertSee('Original company')->assertDontSee('Changed live vacancy');

        $selects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));
        $this->assertLessThanOrEqual(5, $selects->count());
        DB::disableQueryLog();
    }

    public function test_application_list_uses_immutable_snapshots_and_stays_private_with_three_queries(): void
    {
        $application = JobApplication::factory()->create(['job_title_snapshot' => 'My submitted vacancy']);
        JobApplication::factory()->create(['job_title_snapshot' => 'Another candidate vacancy']);
        $this->actingAs($application->candidate);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get('/candidate/applications')->assertOk()->assertSee('My submitted vacancy')->assertDontSee('Another candidate vacancy');

        $selects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));
        $this->assertLessThanOrEqual(3, $selects->count());
        DB::disableQueryLog();
    }
}
