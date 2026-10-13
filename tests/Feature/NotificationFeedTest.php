<?php

namespace Tests\Feature;

use App\Models\ApplicationStatusHistory;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_feed_redirects_to_login_and_empty_candidate_feed_is_private(): void
    {
        $this->getJson('/candidate/notifications/feed')->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->candidate()->create())->getJson('/candidate/notifications/feed')
            ->assertOk()->assertExactJson(['unread_count' => 0, 'items' => []])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_read_candidate_notification_feed(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        if ($role === 'admin') {
            $this->withSession($this->adminMfaSession($user));
        }

        $this->actingAs($user)->getJson('/candidate/notifications/feed')->assertForbidden();
    }

    public static function otherRoles(): array
    {
        return ['employer' => ['employer'], 'admin' => ['admin']];
    }

    public function test_blocked_candidate_cannot_read_private_notification_feed(): void
    {
        $this->actingAs(User::factory()->candidate()->blocked()->create())->getJson('/candidate/notifications/feed')
            ->assertRedirectToRoute('login');
    }

    public function test_feed_is_scoped_to_candidate_excludes_initial_history_and_has_bounded_recent_items(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(10, 0));
        $application = JobApplication::factory()->create();
        $candidate = User::findOrFail($application->candidate_id);
        $notices = ApplicationStatusHistory::factory()->count(10)->create([
            'application_id' => $application->id,
            'changed_by' => $application->job->company->owner_user_id,
            'from_status' => 'SUBMITTED',
            'to_status' => 'REVIEWING',
        ]);
        DB::table('application_status_histories')->where('id', $notices->last()->id)->update(['candidate_read_at' => now()]);
        ApplicationStatusHistory::factory()->create(['application_id' => $application->id]);
        $other = JobApplication::factory()->create(['job_title_snapshot' => 'Private other job', 'company_name_snapshot' => 'Private other company']);
        ApplicationStatusHistory::factory()->create(['application_id' => $other->id, 'from_status' => 'REVIEWING', 'to_status' => 'SELECTED']);

        $response = $this->actingAs($candidate)->getJson('/candidate/notifications/feed?candidate_id='.$other->candidate_id)
            ->assertOk()->assertJsonPath('unread_count', 9)->assertJsonCount(8, 'items')
            ->assertJsonPath('items.0.id', $notices->last()->id)->assertJsonPath('items.0.unread', false)
            ->assertJsonPath('items.1.unread', true)->assertJsonPath('items.0.changed_at', '2026-10-14T10:00:00+00:00')
            ->assertJsonPath('items.7.id', $notices[2]->id)
            ->assertJsonPath('items.0.url', '/applications/'.$application->id)
            ->assertJsonPath('items.0.read_url', '/candidate/notifications/'.$notices->last()->id.'/read')
            ->assertDontSee('Private other job')->assertDontSee('Private other company');

        $this->assertSame(['id', 'title', 'body', 'status', 'unread', 'changed_at', 'url', 'read_url'], array_keys($response->json('items.0')));
        $this->assertDatabaseHas('application_status_histories', ['id' => $notices->first()->id, 'candidate_read_at' => null]);
    }

    public function test_employer_selection_appears_as_unread_acceptance_notification_and_is_marked_read_once(): void
    {
        $this->freezeTime();
        $application = JobApplication::factory()->create(['status' => 'REVIEWING', 'job_title_snapshot' => 'Frontend Intern', 'company_name_snapshot' => 'Bridge Studio']);
        $this->actingAs($application->job->company->owner)->patch(route('employer.applications.update', $application), ['version' => 1, 'status' => 'SELECTED'])
            ->assertRedirectToRoute('applications.show', $application);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'SELECTED', 'version' => 2]);
        $notice = ApplicationStatusHistory::where('application_id', $application->id)->sole();
        $this->assertDatabaseHas('application_status_histories', ['id' => $notice->id, 'from_status' => 'REVIEWING', 'to_status' => 'SELECTED', 'candidate_read_at' => null]);

        $this->actingAs(User::findOrFail($application->candidate_id))->getJson('/candidate/notifications/feed')
            ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.title', 'Bạn đã được tuyển chọn!')
            ->assertJsonPath('items.0.body', 'Bridge Studio đã chọn bạn cho vị trí Frontend Intern. Xem chi tiết hồ sơ ứng tuyển để theo dõi bước tiếp theo.')
            ->assertJsonPath('items.0.status', 'SELECTED')->assertJsonPath('items.0.unread', true);
        $this->postJson(route('candidate.notifications.read', $notice->id))->assertOk()->assertExactJson(['unread_count' => 0])
            ->assertHeader('Cache-Control', 'no-store, private');
        $readAt = DB::table('application_status_histories')->where('id', $notice->id)->value('candidate_read_at');
        $this->assertNotNull($readAt);
        $this->travel(1)->minute();
        $this->postJson(route('candidate.notifications.read', $notice->id))->assertOk()->assertExactJson(['unread_count' => 0]);
        $this->assertSame($readAt, DB::table('application_status_histories')->where('id', $notice->id)->value('candidate_read_at'));
        $this->getJson('/candidate/notifications/feed')->assertJsonPath('items.0.unread', false);
    }

    public function test_json_mark_read_cannot_read_another_candidate_or_initial_status(): void
    {
        $other = ApplicationStatusHistory::factory()->create(['from_status' => 'REVIEWING', 'to_status' => 'SELECTED']);
        $initial = ApplicationStatusHistory::factory()->create();
        $this->actingAs(User::findOrFail($initial->application->candidate_id))->postJson(route('candidate.notifications.read', $other->id))->assertNotFound();
        $this->postJson(route('candidate.notifications.read', $initial->id))->assertNotFound();
        $this->assertDatabaseHas('application_status_histories', ['id' => $other->id, 'candidate_read_at' => null]);
        $this->assertDatabaseHas('application_status_histories', ['id' => $initial->id, 'candidate_read_at' => null]);
    }

    #[DataProvider('feedLanguages')]
    public function test_feed_localizes_acceptance_and_regular_updates(string $locale, string $selectedTitle, string $updatedTitle, string $status): void
    {
        $application = JobApplication::factory()->create(['job_title_snapshot' => 'Frontend Intern', 'company_name_snapshot' => 'Bridge Studio']);
        ApplicationStatusHistory::factory()->create(['application_id' => $application->id, 'from_status' => 'REVIEWING', 'to_status' => 'SELECTED']);
        ApplicationStatusHistory::factory()->create(['application_id' => $application->id, 'from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING']);

        $this->actingAs(User::findOrFail($application->candidate_id))->withSession(['locale' => $locale])->getJson('/candidate/notifications/feed')
            ->assertOk()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('items.0.title', $updatedTitle)->assertJsonPath('items.1.title', $selectedTitle)
            ->assertJsonPath('items.0.body', fn (string $body): bool => str_contains($body, $status) && str_contains($body, 'Frontend Intern') && str_contains($body, 'Bridge Studio'));
    }

    public static function feedLanguages(): array
    {
        return [
            'Vietnamese' => ['vi', 'Bạn đã được tuyển chọn!', 'Hồ sơ ứng tuyển có cập nhật', 'Đang xem xét'],
            'English' => ['en', 'You have been selected!', 'Your application has an update', 'Under review'],
            'Japanese' => ['ja', '採用が決まりました！', '応募状況が更新されました', '選考中'],
        ];
    }

    public function test_acceptance_page_escapes_job_and_company_content(): void
    {
        $application = JobApplication::factory()->create(['job_title_snapshot' => '<script>alert(1)</script>', 'company_name_snapshot' => '<img src=x onerror=alert(2)>']);
        ApplicationStatusHistory::factory()->create(['application_id' => $application->id, 'from_status' => 'REVIEWING', 'to_status' => 'SELECTED']);

        $this->actingAs(User::findOrFail($application->candidate_id))->get('/candidate/notifications')
            ->assertOk()->assertSee('Bạn đã được tuyển chọn!')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)->assertDontSee('<img src=x onerror=alert(2)>', false);
    }
}
