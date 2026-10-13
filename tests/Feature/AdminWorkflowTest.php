<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_pages_render_and_escape_user_content(): void
    {
        $admin = $this->account('admin');
        $candidate = $this->account('candidate');
        $job = JobPosting::factory()->create(['title' => '<script>alert(1)</script>', 'moderation_state' => 'PENDING']);
        $report = Report::factory()->create(['job_id' => $job->id, 'reporter_id' => $candidate->id]);
        $this->confirmedAdmin($admin);

        foreach (['admin.dashboard', 'admin.users', 'admin.jobs', 'admin.reports', 'admin.statistics'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('admin.users.show', $candidate))->assertOk()->assertDontSee('/cvs/');
        $this->get(route('admin.jobs.show', $job))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('admin.reports.show', $report))->assertOk();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_moderate_jobs(string $role): void
    {
        $job = JobPosting::factory()->create(['moderation_state' => 'PENDING']);

        $this->actingAs($this->account($role))->post(route('admin.jobs.moderate', $job), ['action' => 'approve', 'version' => $job->version, 'reason' => 'Hợp lệ'])->assertForbidden();

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'moderation_state' => 'PENDING']);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public static function nonAdminRoles(): array
    {
        return [['candidate'], ['employer']];
    }

    public function test_account_block_and_unblock_preserve_job_states_and_record_history(): void
    {
        $owner = $this->account('employer');
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);
        $job = JobPosting::factory()->create(['company_id' => $company->id, 'publication_state' => 'CLOSED', 'moderation_state' => 'HIDDEN']);
        $admin = $this->account('admin');

        $this->confirmedAdmin($admin)->patch(route('admin.users.status', $owner), ['current_status' => 'ACTIVE', 'status' => 'BLOCKED', 'reason' => 'Vi phạm quy định'])->assertRedirect();
        $this->patch(route('admin.users.status', $owner), ['current_status' => 'BLOCKED', 'status' => 'ACTIVE', 'reason' => 'Đã xác minh xử lý'])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'publication_state' => 'CLOSED', 'moderation_state' => 'HIDDEN']);
        $this->assertDatabaseHas('account_status_histories', ['target_user_id' => $owner->id, 'admin_id' => $admin->id, 'from_status' => 'ACTIVE', 'to_status' => 'BLOCKED', 'reason' => 'Vi phạm quy định']);
        $this->assertDatabaseCount('account_status_histories', 2);
    }

    public function test_stale_account_status_is_409_and_cannot_override_newer_change(): void
    {
        $candidate = $this->account('candidate');
        $candidate->update(['status' => 'BLOCKED']);

        $this->confirmedAdmin($this->account('admin'))->patch(route('admin.users.status', $candidate), ['current_status' => 'ACTIVE', 'status' => 'ACTIVE', 'reason' => 'Trang cũ'])->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $candidate->id, 'status' => 'BLOCKED']);
        $this->assertDatabaseCount('account_status_histories', 0);
    }

    public function test_admin_account_cannot_be_blocked_through_business_account_endpoint(): void
    {
        $admin = $this->account('admin');

        $this->confirmedAdmin($admin)->patch(route('admin.users.status', $admin), ['current_status' => 'ACTIVE', 'status' => 'BLOCKED', 'reason' => 'Không hợp lệ'])->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'ACTIVE']);
    }

    public function test_company_hide_restore_is_audited_without_reopening_closed_job(): void
    {
        $company = Company::factory()->create(['visibility' => 'VISIBLE']);
        $job = JobPosting::factory()->create(['company_id' => $company->id, 'publication_state' => 'CLOSED']);
        $this->confirmedAdmin($this->account('admin'));

        $this->patch(route('admin.companies.visibility', $company), ['current_visibility' => 'VISIBLE', 'visibility' => 'HIDDEN', 'reason' => 'Cần xem xét'])->assertRedirect();
        $this->patch(route('admin.companies.visibility', $company), ['current_visibility' => 'HIDDEN', 'visibility' => 'VISIBLE', 'reason' => 'Đã xử lý'])->assertRedirect();

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'visibility' => 'VISIBLE']);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'publication_state' => 'CLOSED']);
        $this->assertDatabaseCount('moderation_actions', 2);
    }

    public function test_approval_opens_first_publication_and_records_reviewed_version(): void
    {
        $this->travelTo(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        $job = JobPosting::factory()->create(['publication_state' => 'DRAFT', 'moderation_state' => 'PENDING', 'published_at' => null, 'version' => 3]);
        $admin = $this->account('admin');

        $this->confirmedAdmin($admin)->post(route('admin.jobs.moderate', $job), ['action' => 'approve', 'version' => 3, 'reason' => 'Đủ nội dung'])->assertRedirect();

        $this->assertSame('OPEN', $job->fresh()->publication_state);
        $this->assertTrue($job->fresh()->published_at->equalTo(now()));
        $this->assertDatabaseHas('moderation_actions', ['job_id' => $job->id, 'admin_id' => $admin->id, 'from_state' => 'PENDING', 'to_state' => 'APPROVED', 'reviewed_version' => 3]);
    }

    public function test_reapproval_keeps_closed_state_and_original_publication_date(): void
    {
        $this->travelTo(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        $published = now()->subDays(3);
        $job = JobPosting::factory()->create(['publication_state' => 'CLOSED', 'moderation_state' => 'PENDING', 'published_at' => $published]);

        $this->confirmedAdmin($this->account('admin'))->post(route('admin.jobs.moderate', $job), ['action' => 'approve', 'version' => $job->version, 'reason' => 'Duyệt lại nội dung'])->assertRedirect();

        $this->assertSame('CLOSED', $job->fresh()->publication_state);
        $this->assertTrue($job->fresh()->published_at->equalTo($published));
    }

    public function test_stale_version_cannot_approve_newer_content(): void
    {
        $job = JobPosting::factory()->create(['moderation_state' => 'PENDING', 'version' => 2]);

        $this->confirmedAdmin($this->account('admin'))->post(route('admin.jobs.moderate', $job), ['action' => 'approve', 'version' => 1, 'reason' => 'Bản cũ'])->assertStatus(409);

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'moderation_state' => 'PENDING']);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    #[DataProvider('ineligibleJobs')]
    public function test_ineligible_job_cannot_be_approved(string $condition): void
    {
        $job = JobPosting::factory()->create(['moderation_state' => 'PENDING']);
        if ($condition === 'expired') {
            $job->update(['deadline_at' => now()->subMinute()]);
        } elseif ($condition === 'hidden_company') {
            $job->company->update(['visibility' => 'HIDDEN']);
        } else {
            $job->company->owner->update(['status' => 'BLOCKED']);
        }

        $this->confirmedAdmin($this->account('admin'))->post(route('admin.jobs.moderate', $job), ['action' => 'approve', 'version' => $job->version, 'reason' => 'Kiểm tra'])->assertSessionHasErrors('action');

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'moderation_state' => 'PENDING']);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public static function ineligibleJobs(): array
    {
        return [['expired'], ['hidden_company'], ['blocked_owner']];
    }

    public function test_reject_requires_reason_and_keeps_record_when_missing(): void
    {
        $job = JobPosting::factory()->create(['moderation_state' => 'PENDING']);
        $this->confirmedAdmin($this->account('admin'));

        $this->post(route('admin.jobs.moderate', $job), ['action' => 'reject', 'version' => $job->version])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('moderation_actions', 0);
        $this->post(route('admin.jobs.moderate', $job), ['action' => 'reject', 'version' => $job->version, 'reason' => 'Thiếu thông tin'])->assertRedirect();

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'moderation_state' => 'REJECTED']);
    }

    public function test_hidden_job_restores_to_review_without_automatic_publication(): void
    {
        $job = JobPosting::factory()->create(['publication_state' => 'CLOSED', 'moderation_state' => 'APPROVED']);
        $this->confirmedAdmin($this->account('admin'));

        $this->post(route('admin.jobs.moderate', $job), ['action' => 'hide', 'version' => $job->version, 'reason' => 'Tạm ẩn'])->assertRedirect();
        $this->post(route('admin.jobs.moderate', $job), ['action' => 'restore', 'version' => $job->version, 'reason' => 'Xem xét lại'])->assertRedirect();

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'moderation_state' => 'PENDING', 'publication_state' => 'CLOSED']);
        $this->assertDatabaseCount('moderation_actions', 2);
    }

    public function test_report_lifecycle_records_actor_note_and_timestamp_without_hiding_job(): void
    {
        $this->travelTo(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        $report = Report::factory()->create(['status' => 'OPEN']);
        $admin = $this->account('admin');
        $this->confirmedAdmin($admin);

        $this->patch(route('admin.reports.update', $report), ['current_status' => 'OPEN', 'status' => 'IN_REVIEW'])->assertRedirect();
        $this->patch(route('admin.reports.update', $report), ['current_status' => 'IN_REVIEW', 'status' => 'RESOLVED', 'resolution_note' => 'Đã xác minh phản ánh'])->assertRedirect();

        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'RESOLVED', 'assigned_admin_id' => $admin->id, 'resolved_by' => $admin->id, 'resolution_note' => 'Đã xác minh phản ánh']);
        $this->assertTrue($report->fresh()->resolved_at->equalTo(now()));
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_report_cannot_skip_review_or_finish_without_note_or_override_final_result(): void
    {
        $report = Report::factory()->create(['status' => 'OPEN']);
        $this->confirmedAdmin($this->account('admin'));

        $this->patch(route('admin.reports.update', $report), ['current_status' => 'OPEN', 'status' => 'RESOLVED', 'resolution_note' => 'Quá sớm'])->assertSessionHasErrors('status');
        $this->patch(route('admin.reports.update', $report), ['current_status' => 'OPEN', 'status' => 'IN_REVIEW'])->assertRedirect();
        $this->patch(route('admin.reports.update', $report), ['current_status' => 'IN_REVIEW', 'status' => 'DISMISSED'])->assertSessionHasErrors('resolution_note');
        $this->patch(route('admin.reports.update', $report), ['current_status' => 'IN_REVIEW', 'status' => 'DISMISSED', 'resolution_note' => 'Không có căn cứ'])->assertRedirect();
        $this->patch(route('admin.reports.update', $report), ['current_status' => 'IN_REVIEW', 'status' => 'RESOLVED', 'resolution_note' => 'Trang cũ'])->assertStatus(409);

        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'DISMISSED', 'resolution_note' => 'Không có căn cứ']);
    }

    public function test_candidate_can_report_public_job_but_not_hidden_job_and_admin_cannot_report(): void
    {
        $job = JobPosting::factory()->create(['publication_state' => 'OPEN', 'moderation_state' => 'APPROVED']);
        $candidate = $this->account('candidate');
        $payload = ['reason' => 'Nội dung không phù hợp', 'description' => 'Cần xem lại nội dung tuyển dụng.'];

        $this->actingAs($candidate)->post(route('reports.store', $job), $payload)->assertRedirect();
        $this->assertDatabaseHas('reports', ['job_id' => $job->id, 'reporter_id' => $candidate->id, 'status' => 'OPEN']);
        $job->update(['moderation_state' => 'HIDDEN']);
        $this->post(route('reports.store', $job), $payload)->assertNotFound();
        $this->confirmedAdmin($this->account('admin'))->post(route('reports.store', $job), $payload)->assertForbidden();
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_statistics_use_local_date_boundaries_and_exclude_to_date(): void
    {
        $this->travelTo(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        $admin = $this->account('admin');
        $user = $this->account('candidate');
        $user->forceFill(['created_at' => '2029-01-01 17:00:00'])->save();
        $atEnd = $this->account('candidate');
        $atEnd->forceFill(['created_at' => '2029-01-02 17:00:00'])->save();
        JobPosting::factory()->create(['published_at' => '2029-01-01 17:00:00', 'publication_state' => 'OPEN', 'moderation_state' => 'APPROVED']);
        JobPosting::factory()->create(['published_at' => '2029-01-02 17:00:00', 'publication_state' => 'CLOSED', 'moderation_state' => 'APPROVED']);
        JobApplication::factory()->create(['applied_at' => '2029-01-01 17:00:00']);
        JobApplication::factory()->create(['applied_at' => '2029-01-02 17:00:00']);

        $this->confirmedAdmin($admin)->get(route('admin.statistics', ['from' => '2029-01-02', 'to' => '2029-01-03']))
            ->assertOk()->assertViewHas('newUsers', 1)->assertViewHas('newApplications', 1)->assertViewHas('publishedJobs', 1);
        $this->get(route('admin.statistics', ['from' => '2029-01-03', 'to' => '2029-01-02']))->assertSessionHasErrors('to');
    }

    private function confirmedAdmin(User $admin): static
    {
        return $this->actingAs($admin)->withSession($this->adminMfaSession($admin));
    }

    private function account(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'ACTIVE', 'is_demo' => true]);
    }
}
