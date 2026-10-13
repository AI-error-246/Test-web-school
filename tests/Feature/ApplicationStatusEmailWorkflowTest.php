<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\ApplicationStatusEmails;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationStatusEmailWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['application_mail.mode' => 'preview', 'app.url' => 'http://localhost']);
        Storage::fake('local');
        Mail::fake();
        Http::preventStrayRequests();
    }

    public function test_status_update_persists_one_email_without_sending_and_ignores_recipient_input(): void
    {
        $application = JobApplication::factory()->create(['email_snapshot' => 'old-address@example.test']);
        $this->actingAs($application->job->company->owner)->patch(route('employer.applications.update', $application), [
            'status' => 'REVIEWING', 'version' => 1, 'email' => 'attacker@example.test', 'mode' => 'send',
        ])->assertRedirect(route('applications.show', $application));
        $email = DB::table('application_status_emails')->sole();
        $this->assertSame($application->candidate->email, $email->recipient_email);
        $this->assertSame('preview', $email->mode);
        $this->assertSame('PENDING', $email->status);
        Mail::assertNothingSent();
        $this->patch(route('employer.applications.update', $application), ['status' => 'REJECTED', 'version' => 1])->assertConflict();
        $this->patch(route('employer.applications.update', $application), ['status' => 'REVIEWING', 'version' => 2])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('application_status_emails', 1);
        $this->assertDatabaseCount('application_status_histories', 1);
        $this->actingAs($application->candidate)->get(route('candidate.notifications'))->assertOk()->assertSee('Chờ xử lý');
        Http::assertNothingSent();
    }

    public function test_foreign_employer_cannot_change_status_or_queue_mail(): void
    {
        $application = JobApplication::factory()->create();
        $this->actingAs(User::factory()->employer()->create())->patch(route('employer.applications.update', $application), ['status' => 'REVIEWING', 'version' => 1])->assertNotFound();
        $this->assertDatabaseCount('application_status_emails', 0);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'SUBMITTED']);
    }

    public function test_outbox_failure_rolls_back_status_and_history_atomically(): void
    {
        $application = JobApplication::factory()->create();
        $this->mock(ApplicationStatusEmails::class, fn ($mock) => $mock->shouldReceive('enqueue')->once()->andThrow(new \RuntimeException('TEST_OUTBOX_FAILURE')));
        $this->actingAs($application->job->company->owner)->patch(route('employer.applications.update', $application), ['status' => 'REVIEWING', 'version' => 1])->assertStatus(500);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'SUBMITTED', 'version' => 1]);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('application_status_emails', 0);
        Mail::assertNothingSent();
    }

    public function test_preview_is_private_to_owner_and_includes_restrictive_headers(): void
    {
        [$application, $email] = $this->preview();
        $url = route('candidate.notifications.email', $email->history_id);
        $this->actingAs($application->candidate)->get(route('candidate.notifications'))->assertSee('Xem email mô phỏng');
        $response = $this->get($url)->assertOk()->assertSee($application->job_title_snapshot)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("default-src 'none'", implode(';', $response->headers->all('Content-Security-Policy')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs(User::factory()->candidate()->create())->get($url)->assertNotFound();
        $this->actingAs($application->job->company->owner)->get($url)->assertForbidden();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession($this->adminMfaSession($admin))->get($url)->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_unready_or_missing_or_tampered_preview_cannot_read_other_private_files(): void
    {
        [$application, $email] = $this->preview();
        $url = route('candidate.notifications.email', $email->history_id);
        $this->actingAs($application->candidate);
        foreach (['PENDING', 'PROCESSING', 'FAILED', 'SENT'] as $status) {
            DB::table('application_status_emails')->where('id', $email->id)->update(['status' => $status]);
            $this->get($url)->assertNotFound();
        }
        Storage::disk('local')->put('sensitive.txt', 'PRIVATE VALUE');
        DB::table('application_status_emails')->where('id', $email->id)->update(['status' => 'PREVIEWED', 'preview_path' => 'sensitive.txt']);
        $this->get($url)->assertNotFound()->assertDontSee('PRIVATE VALUE');
        DB::table('application_status_emails')->where('id', $email->id)->update(['preview_path' => 'status-email-previews/'.$email->id.'.html']);
        Storage::disk('local')->delete('status-email-previews/'.$email->id.'.html');
        $this->get($url)->assertNotFound();
    }

    public function test_guest_and_blocked_candidate_cannot_read_email_preview(): void
    {
        [$application, $email] = $this->preview();
        $this->post(route('logout'))->assertRedirect();
        $url = route('candidate.notifications.email', $email->history_id);
        $this->get($url)->assertRedirect(route('login'));
        $application->candidate->update(['status' => 'BLOCKED']);
        $this->actingAs($application->candidate)->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    private function preview(): array
    {
        $application = JobApplication::factory()->create();
        $this->actingAs($application->job->company->owner)->patch(route('employer.applications.update', $application), ['status' => 'REVIEWING', 'version' => 1])->assertRedirect();
        $email = DB::table('application_status_emails')->sole();
        $this->assertSame('PREVIEWED', app(ApplicationStatusEmails::class)->deliver($email->id));

        return [$application, $email];
    }
}
