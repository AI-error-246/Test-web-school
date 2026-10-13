<?php

namespace Tests\Feature;

use App\Models\ApplicationStatusHistory;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CandidateUpdatesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_and_other_roles_cannot_access_candidate_updates(): void
    {
        $this->get('/candidate/notifications')->assertRedirectToRoute('login');
        $this->post('/candidate/saved-searches', ['name' => 'Private'])->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->create(['role' => 'employer']))->get('/candidate/saved-searches')->assertForbidden();
        $this->get('/candidate/notifications')->assertForbidden();
    }

    public function test_committed_employer_transition_creates_unread_update_and_invalid_changes_create_none(): void
    {
        $application = JobApplication::factory()->create();
        $this->actingAs($application->job->company->owner)->patch(route('employer.applications.update', $application), ['version' => 1, 'status' => 'REVIEWING'])->assertRedirect();
        $this->patch(route('employer.applications.update', $application), ['version' => 1, 'status' => 'REJECTED'])->assertConflict();
        $this->patch(route('employer.applications.update', $application), ['version' => 2, 'status' => 'REVIEWING'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('application_status_histories', 1);
        $this->actingAs(User::findOrFail($application->candidate_id))->get('/candidate/notifications?unread=1')->assertOk()->assertViewHas('unreadCount', 1)->assertSee($application->job_title_snapshot)->assertSee('Chưa đọc');
    }

    public function test_notification_scope_read_idempotency_and_initial_status_exclusion(): void
    {
        $notice = ApplicationStatusHistory::factory()->create(['from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING']);
        $candidate = User::findOrFail($notice->application->candidate_id);
        ApplicationStatusHistory::factory()->create(['application_id' => $notice->application_id, 'changed_by' => $candidate->id]);
        $other = ApplicationStatusHistory::factory()->create(['from_status' => 'SUBMITTED', 'to_status' => 'REJECTED']);
        $this->actingAs($candidate)->post('/candidate/notifications/'.$other->id.'/read')->assertNotFound();
        $this->get('/candidate/notifications')->assertOk()->assertViewHas('notifications', fn ($items) => $items->total() === 1);
        $this->post('/candidate/notifications/'.$notice->id.'/read')->assertRedirectToRoute('candidate.notifications');
        $readAt = DB::table('application_status_histories')->where('id', $notice->id)->value('candidate_read_at');
        $this->assertNotNull($readAt);
        $this->travel(1)->minute();
        $this->post('/candidate/notifications/'.$notice->id.'/read')->assertRedirect();
        $this->assertSame($readAt, DB::table('application_status_histories')->where('id', $notice->id)->value('candidate_read_at'));
        $this->get('/candidate/notifications?unread=1')->assertViewHas('unreadCount', 0)->assertViewHas('notifications', fn ($items) => $items->isEmpty());
        $this->assertDatabaseHas('application_status_histories', ['id' => $notice->id, 'from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING']);
    }

    public function test_saved_search_roundtrip_escapes_text_ignores_owner_and_url_injection_and_scopes_delete(): void
    {
        $candidate = User::factory()->create(['role' => 'candidate']);
        $other = User::factory()->create(['role' => 'candidate']);
        $category = JobCategory::factory()->create();
        $location = Location::factory()->create();
        $this->actingAs($candidate)->post('/candidate/saved-searches', ['name' => '<script>alert(1)</script>', 'q' => 'Laravel', 'category' => $category->id, 'location' => $location->id, 'mode' => 'REMOTE', 'sort' => 'deadline', 'candidate_id' => $other->id, 'url' => 'https://evil.example'])->assertRedirectToRoute('candidate.saved-searches');
        $saved = DB::table('candidate_saved_searches')->first();
        $this->assertSame($candidate->id, $saved->candidate_id);
        $this->assertSame(['q' => 'Laravel', 'category' => $category->id, 'location' => $location->id, 'mode' => 'REMOTE', 'sort' => 'deadline'], json_decode($saved->filters, true));
        $this->get('/candidate/saved-searches')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('/jobs?q=Laravel', false)->assertDontSee('evil.example');
        $this->actingAs($other)->get('/candidate/saved-searches')->assertViewHas('searches', fn ($rows) => $rows->isEmpty());
        $this->delete('/candidate/saved-searches/'.$saved->id)->assertNotFound();
        $this->assertDatabaseCount('candidate_saved_searches', 1);
        $this->actingAs($candidate)->delete('/candidate/saved-searches/'.$saved->id)->assertRedirectToRoute('candidate.saved-searches');
        $this->assertDatabaseCount('candidate_saved_searches', 0);
    }

    #[DataProvider('invalidSearches')]
    public function test_invalid_saved_search_is_rejected(array $payload, string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'candidate']))->post('/candidate/saved-searches', $payload)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('candidate_saved_searches', 0);
    }

    public static function invalidSearches(): array
    {
        return [
            'missing name' => [[], 'name'],
            'long name' => [['name' => str_repeat('a', 81)], 'name'],
            'long query' => [['name' => 'Test', 'q' => str_repeat('a', 121)], 'q'],
            'unknown category' => [['name' => 'Test', 'category' => 999999], 'category'],
            'unknown location' => [['name' => 'Test', 'location' => 999999], 'location'],
            'invalid mode' => [['name' => 'Test', 'mode' => 'OTHER'], 'mode'],
            'invalid sort' => [['name' => 'Test', 'sort' => 'evil'], 'sort'],
        ];
    }

    public function test_saved_search_limit_is_per_candidate_and_delete_frees_capacity(): void
    {
        $candidate = User::factory()->create(['role' => 'candidate']);
        $this->actingAs($candidate);
        for ($i = 0; $i < 10; $i++) {
            $this->post('/candidate/saved-searches', ['name' => 'Search '.$i])->assertRedirectToRoute('candidate.saved-searches');
        }
        $this->post('/candidate/saved-searches', ['name' => 'Overflow'])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('candidate_saved_searches', 10);
        $this->actingAs(User::factory()->create(['role' => 'candidate']))->post('/candidate/saved-searches', ['name' => 'Other'])->assertRedirectToRoute('candidate.saved-searches');
        $id = DB::table('candidate_saved_searches')->where('candidate_id', $candidate->id)->value('id');
        $this->actingAs($candidate)->delete('/candidate/saved-searches/'.$id)->assertRedirect();
        $this->post('/candidate/saved-searches', ['name' => 'Replacement'])->assertRedirectToRoute('candidate.saved-searches');
        $this->assertDatabaseCount('candidate_saved_searches', 11);
    }

    public function test_notifications_are_paginated_without_losing_unread_filter(): void
    {
        $application = JobApplication::factory()->create();
        ApplicationStatusHistory::factory()->count(16)->create(['application_id' => $application->id, 'changed_by' => $application->candidate_id, 'from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING']);
        $this->actingAs(User::findOrFail($application->candidate_id))->get('/candidate/notifications?unread=1')->assertOk()->assertViewHas('notifications', fn ($items) => $items->count() === 15 && $items->total() === 16)->assertSee('unread=1', false);
    }

    public function test_migration_preserves_existing_history_and_marks_it_read_without_backlog(): void
    {
        $this->freezeTime();
        $notice = ApplicationStatusHistory::factory()->create(['from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING', 'changed_at' => now()->subDay()]);
        $migration = require database_path('migrations/2026_10_03_133831_add_candidate_read_at_to_application_status_histories.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('application_status_histories', ['id' => $notice->id, 'from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING', 'changed_at' => $notice->changed_at]);
        $this->assertNotNull(DB::table('application_status_histories')->where('id', $notice->id)->value('candidate_read_at'));
        $this->actingAs(User::findOrFail($notice->application->candidate_id))->get('/candidate/notifications?unread=1')->assertViewHas('unreadCount', 0);
    }
}
