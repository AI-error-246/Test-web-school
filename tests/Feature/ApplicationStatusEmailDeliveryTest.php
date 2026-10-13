<?php

namespace Tests\Feature;

use App\Mail\ApplicationStatusChanged;
use App\Models\ApplicationStatusHistory;
use App\Services\ApplicationStatusEmails;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ApplicationStatusEmailDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['application_mail.mode' => 'preview', 'application_mail.mailer' => 'smtp',
            'app.url' => 'http://127.0.0.1:3001']);
    }

    public function test_enqueue_is_unique_atomic_and_uses_account_email_instead_of_application_snapshot(): void
    {
        $history = $this->history();
        $history->application->candidate->update(['email' => 'verified-account@example.test', 'name' => 'Sinh viên kiểm thử']);
        $history->application->update(['email_snapshot' => 'profile-address@example.test']);
        Mail::fake();
        Storage::fake('local');

        DB::transaction(function () use ($history): void {
            app(ApplicationStatusEmails::class)->enqueue($history);
            app(ApplicationStatusEmails::class)->enqueue($history);
        });

        $this->assertDatabaseCount('application_status_emails', 1);
        $this->assertDatabaseHas('application_status_emails', ['history_id' => $history->id,
            'recipient_email' => 'verified-account@example.test', 'recipient_name' => 'Sinh viên kiểm thử',
            'status' => 'PENDING', 'mode' => 'preview', 'attempts' => 0]);
        Mail::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rolled_back_status_transaction_leaves_no_pending_email(): void
    {
        $history = $this->history();
        DB::beginTransaction();
        app(ApplicationStatusEmails::class)->enqueue($history);
        DB::rollBack();

        $this->assertDatabaseCount('application_status_emails', 0);
    }

    public function test_enqueue_outside_transaction_is_refused(): void
    {
        $history = $this->history();
        $this->expectException(LogicException::class);

        app(ApplicationStatusEmails::class)->enqueue($history);
    }

    public function test_initial_submission_is_not_a_status_change_email(): void
    {
        $history = ApplicationStatusHistory::factory()->create();
        DB::transaction(fn () => app(ApplicationStatusEmails::class)->enqueue($history));

        $this->assertDatabaseCount('application_status_emails', 0);
    }

    public function test_invalid_mode_cannot_fall_back_to_sending(): void
    {
        config(['application_mail.mode' => 'invalid']);
        Mail::fake();
        $id = $this->enqueueHistory($this->history());

        $this->assertSame('NOT_CLAIMED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'FAILED', 'error_code' => 'INVALID_MODE', 'attempts' => 0]);
        Mail::assertNothingSent();
    }

    public function test_preview_is_private_escaped_and_never_reported_as_sent(): void
    {
        $this->freezeTime();
        $history = $this->history();
        $history->application->candidate->update(['name' => '<script>private-name</script>']);
        $history->application->update(['job_title_snapshot' => '<img src=x onerror=alert(1)>', 'company_name_snapshot' => '<script>company</script>']);
        $id = $this->enqueueHistory($history);
        Mail::fake();
        Storage::fake('local');

        $this->assertSame('PREVIEWED', app(ApplicationStatusEmails::class)->deliver($id));

        $path = 'status-email-previews/'.$id.'.html';
        $html = Storage::disk('local')->get($path);
        $this->assertStringContainsString('MÔ PHỎNG — chưa gửi email.', $html);
        $this->assertStringContainsString('&lt;script&gt;private-name&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;company&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('http://127.0.0.1:3001/applications/'.$history->application_id, $html);
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'PREVIEWED', 'preview_path' => $path, 'attempts' => 1]);
        $this->assertNotNull(DB::table('application_status_emails')->find($id)->completed_at);
        Mail::assertNothingSent();
    }

    public function test_delivery_cannot_observe_uncommitted_status_change(): void
    {
        $id = $this->enqueueHistory($this->history());
        Mail::fake();
        DB::beginTransaction();
        try {
            app(ApplicationStatusEmails::class)->deliver($id);
            $this->fail('Delivery should require committed data.');
        } catch (LogicException $exception) {
            $this->assertSame('Status email delivery requires committed data.', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'PENDING', 'attempts' => 0]);
        Mail::assertNothingSent();
    }

    #[DataProvider('unavailableRecipients')]
    public function test_recipient_is_checked_again_before_delivery(array $changes): void
    {
        $history = $this->history();
        $id = $this->enqueueHistory($history);
        $history->application->candidate->update($changes);
        Mail::fake();
        Storage::fake('local');

        $this->assertSame('SKIPPED', app(ApplicationStatusEmails::class)->deliver($id));

        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'error_code' => 'RECIPIENT_UNAVAILABLE', 'attempts' => 1]);
        Mail::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function unavailableRecipients(): array
    {
        return [
            'blocked' => [['status' => 'BLOCKED']],
            'email changed' => [['email' => 'changed@example.test']],
            'role changed' => [['role' => 'employer']],
        ];
    }

    public function test_invalid_account_email_is_skipped(): void
    {
        $history = $this->history();
        $history->application->candidate->update(['email' => 'invalid-address']);
        $id = $this->enqueueHistory($history);
        Mail::fake();

        $this->assertSame('SKIPPED', app(ApplicationStatusEmails::class)->deliver($id));
        Mail::assertNothingSent();
    }

    public function test_previous_preview_remains_preview_after_enabling_send(): void
    {
        $id = $this->enqueueHistory($this->history());
        $this->configureSmtp();
        Mail::fake();
        Storage::fake('local');

        $this->assertSame('PREVIEWED', app(ApplicationStatusEmails::class)->deliver($id));
        Mail::assertNothingSent();
    }

    public function test_pending_send_is_stopped_when_send_mode_is_disabled(): void
    {
        $this->configureSmtp();
        $id = $this->enqueueHistory($this->history());
        config(['application_mail.mode' => 'preview']);
        Mail::fake();

        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'error_code' => 'SEND_DISABLED']);
        Mail::assertNothingSent();
    }

    #[DataProvider('unsafeOrigins')]
    public function test_misconfigured_canonical_origin_does_not_create_email(string $origin): void
    {
        $id = $this->enqueueHistory($this->history());
        config(['app.url' => $origin]);
        Mail::fake();
        Storage::fake('local');

        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'error_code' => 'INVALID_APP_URL']);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
    }

    public static function unsafeOrigins(): array
    {
        return [['http://external.example.test'], ['https://user:secret@example.test'], ['https://example.test?unexpected=value'], ['https://example.test/subpath']];
    }

    #[DataProvider('unsafeSmtpSettings')]
    public function test_send_requires_explicit_smtp_and_cannot_report_fake_transport_as_sent(array $settings): void
    {
        $this->configureSmtp();
        config($settings);
        $id = $this->enqueueHistory($this->history());
        Mail::fake();

        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'error_code' => 'SMTP_NOT_CONFIGURED']);
        Mail::assertNothingSent();
    }

    public static function unsafeSmtpSettings(): array
    {
        return [
            'log mailer' => [['mail.mailers.smtp.transport' => 'log']],
            'array mailer' => [['mail.mailers.smtp.transport' => 'array']],
            'failover mailer' => [['mail.mailers.smtp.transport' => 'failover']],
            'URL overrides SMTP transport' => [['mail.mailers.smtp.url' => 'log://unused']],
            'default sender' => [['mail.from.address' => 'hello@example.com']],
            'unbounded timeout' => [['mail.mailers.smtp.timeout' => null]],
            'default localhost' => [['mail.mailers.smtp.host' => '127.0.0.1']],
            'URL removes timeout bound' => [['mail.mailers.smtp.url' => 'smtp://smtp.example.test:587?timeout=0']],
        ];
    }

    public function test_successful_smtp_delivery_is_claimed_once_and_never_replayed(): void
    {
        $this->configureSmtp();
        $history = $this->history();
        $id = $this->enqueueHistory($history);
        $pending = $this->smtpPending($history->application->candidate->email);
        $pending->shouldReceive('send')->once()->with(Mockery::on(fn (ApplicationStatusChanged $mail): bool => ! $mail->isPreview
            && $mail->jobTitle === $history->application->job_title_snapshot && $mail->attachments() === []))
            ->andReturn(Mockery::mock(SentMessage::class));

        $this->assertSame('SENT', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertSame('NOT_CLAIMED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'SENT', 'attempts' => 1, 'preview_path' => null]);
    }

    public function test_cancelled_mail_is_not_marked_sent(): void
    {
        $this->configureSmtp();
        $history = $this->history();
        $id = $this->enqueueHistory($history);
        $this->smtpPending($history->application->candidate->email)->shouldReceive('send')->once()->andReturnNull();

        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'FAILED', 'error_code' => 'MAIL_NOT_ACCEPTED']);
    }

    public function test_ambiguous_smtp_failure_is_sanitized_and_not_retried(): void
    {
        $this->configureSmtp();
        $history = $this->history();
        $id = $this->enqueueHistory($history);
        $this->smtpPending($history->application->candidate->email)->shouldReceive('send')->once()
            ->andThrow(new RuntimeException('private-smtp-password-and-address'));

        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertSame('NOT_CLAIMED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->artisan('app:deliver-application-emails', ['--retry-preview-failed' => true])->assertSuccessful();
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'error_code' => 'MAIL_DELIVERY_UNCERTAIN', 'attempts' => 1]);
    }

    public function test_processing_record_is_not_reclaimed_after_interruption(): void
    {
        $id = $this->enqueueHistory($this->history());
        DB::table('application_status_emails')->where('id', $id)->update(['status' => 'PROCESSING', 'attempts' => 1]);
        Mail::fake();

        $this->assertSame('NOT_CLAIMED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->artisan('app:deliver-application-emails', ['--retry-preview-failed' => true])->assertSuccessful();
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'PROCESSING', 'attempts' => 1]);
        Mail::assertNothingSent();
    }

    public function test_preview_failure_can_be_explicitly_retried_without_smtp_delivery(): void
    {
        $id = $this->enqueueHistory($this->history());
        config(['app.url' => 'invalid']);
        Mail::fake();
        Storage::fake('local');
        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        config(['app.url' => 'http://127.0.0.1:3001']);

        $this->artisan('app:deliver-application-emails')->assertSuccessful();
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'FAILED', 'attempts' => 1]);
        $this->artisan('app:deliver-application-emails', ['--retry-preview-failed' => true])->assertSuccessful();
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'PREVIEWED', 'attempts' => 2]);
        Mail::assertNothingSent();
    }

    #[DataProvider('storageFailures')]
    public function test_private_preview_write_failure_is_recorded_without_leaking_filesystem_details(bool $throws): void
    {
        $id = $this->enqueueHistory($this->history());
        Mail::fake();
        $disk = Mockery::mock(FilesystemAdapter::class);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $write = $disk->shouldReceive('put')->once()->with('status-email-previews/'.$id.'.html', Mockery::type('string'), ['visibility' => 'private']);
        if ($throws) {
            $write->andThrow(new RuntimeException('private-storage-location'));
        } else {
            $write->andReturnFalse();
        }

        $this->assertSame('FAILED', app(ApplicationStatusEmails::class)->deliver($id));
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'FAILED', 'error_code' => 'PREVIEW_WRITE_FAILED', 'preview_path' => null]);
        Mail::assertNothingSent();
    }

    public static function storageFailures(): array
    {
        return ['failed write' => [false], 'storage exception' => [true]];
    }

    public function test_command_limits_batch_and_reports_only_status_counts(): void
    {
        $first = $this->enqueueHistory($this->history());
        $second = $this->enqueueHistory($this->history());
        Mail::fake();
        Storage::fake('local');

        $this->artisan('app:deliver-application-emails', ['--limit' => 1])
            ->expectsOutput('Processed 1 message(s).')->expectsOutput('PREVIEWED: 1')->assertSuccessful();

        $this->assertDatabaseHas('application_status_emails', ['id' => $first, 'status' => 'PREVIEWED']);
        $this->assertDatabaseHas('application_status_emails', ['id' => $second, 'status' => 'PENDING']);
        Mail::assertNothingSent();
    }

    public function test_command_rejects_invalid_limit_without_claiming_messages(): void
    {
        $id = $this->enqueueHistory($this->history());

        $this->artisan('app:deliver-application-emails', ['--limit' => 0])->assertExitCode(2);
        $this->assertDatabaseHas('application_status_emails', ['id' => $id, 'status' => 'PENDING', 'attempts' => 0]);
    }

    private function history(): ApplicationStatusHistory
    {
        return ApplicationStatusHistory::factory()->create(['from_status' => 'SUBMITTED', 'to_status' => 'REVIEWING']);
    }

    private function enqueueHistory(ApplicationStatusHistory $history): int
    {
        DB::transaction(fn () => app(ApplicationStatusEmails::class)->enqueue($history));

        return (int) DB::table('application_status_emails')->where('history_id', $history->id)->value('id');
    }

    private function configureSmtp(): void
    {
        config(['application_mail.mode' => 'send', 'mail.from.address' => 'notifications@example.test',
            'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587, 'mail.mailers.smtp.timeout' => 10, 'mail.mailers.smtp.url' => null]);
    }

    private function smtpPending(string $recipient): PendingMail
    {
        $mailer = Mockery::mock(Mailer::class);
        $pending = Mockery::mock(PendingMail::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('to')->once()->with($recipient)->andReturn($pending);

        return $pending;
    }
}
