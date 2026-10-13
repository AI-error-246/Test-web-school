<?php

namespace App\Services;

use App\Mail\ApplicationStatusChanged;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

class ApplicationStatusEmails
{
    public function enqueue(ApplicationStatusHistory $history): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Status email must be recorded inside the status transaction.');
        }
        if (! $history->exists || ! in_array($history->from_status, ['SUBMITTED', 'REVIEWING'], true)
            || ! in_array($history->to_status, ['REVIEWING', 'SELECTED', 'REJECTED'], true)
            || $history->from_status === $history->to_status) {
            return;
        }

        $application = $history->application()->with('candidate')->firstOrFail();
        $candidate = $application->candidate;
        $mode = config('application_mail.mode');
        $validMode = in_array($mode, ['preview', 'send'], true);

        DB::table('application_status_emails')->insertOrIgnore([
            'history_id' => $history->id,
            'application_id' => $application->id,
            'candidate_id' => $candidate->id,
            'recipient_email' => $candidate->email,
            'recipient_name' => $candidate->name,
            'job_title' => $application->job_title_snapshot,
            'company_name' => $application->company_name_snapshot,
            'from_status' => $history->from_status,
            'to_status' => $history->to_status,
            'mode' => $validMode ? $mode : 'preview',
            'status' => $validMode ? 'PENDING' : 'FAILED',
            'error_code' => $validMode ? null : 'INVALID_MODE',
            'completed_at' => $validMode ? null : now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function deliver(int $id): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Status email delivery requires committed data.');
        }

        $claimed = DB::table('application_status_emails')->where('id', $id)->where('status', 'PENDING')->update([
            'status' => 'PROCESSING', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ]);
        if ($claimed !== 1) {
            return 'NOT_CLAIMED';
        }

        $email = DB::table('application_status_emails')->find($id);
        $candidate = User::find($email->candidate_id);
        if (! $candidate || $candidate->role !== 'candidate' || $candidate->status !== 'ACTIVE'
            || ! $this->validEmail($email->recipient_email)
            || strcasecmp($candidate->email, $email->recipient_email) !== 0) {
            return $this->finish($id, 'SKIPPED', 'RECIPIENT_UNAVAILABLE');
        }

        $url = $this->applicationUrl((int) $email->application_id);
        if ($url === null) {
            return $this->finish($id, 'FAILED', 'INVALID_APP_URL');
        }

        $message = new ApplicationStatusChanged($email->recipient_name, $email->job_title,
            $email->company_name, $email->to_status, $url, $email->mode === 'preview');

        if ($email->mode === 'preview') {
            $path = 'status-email-previews/'.$id.'.html';
            try {
                if (! Storage::disk('local')->put($path, $message->render(), ['visibility' => 'private'])) {
                    return $this->finish($id, 'FAILED', 'PREVIEW_WRITE_FAILED');
                }
            } catch (Throwable) {
                return $this->finish($id, 'FAILED', 'PREVIEW_WRITE_FAILED');
            }

            return $this->finish($id, 'PREVIEWED', null, $path);
        }

        $mailer = config('application_mail.mailer');
        if (config('application_mail.mode') !== 'send') {
            return $this->finish($id, 'FAILED', 'SEND_DISABLED');
        }
        if ($email->mode !== 'send' || ! $this->smtpConfigured($mailer)) {
            return $this->finish($id, 'FAILED', 'SMTP_NOT_CONFIGURED');
        }

        try {
            $sent = Mail::mailer($mailer)->to($email->recipient_email)->send($message);
        } catch (Throwable) {
            return $this->finish($id, 'FAILED', 'MAIL_DELIVERY_UNCERTAIN');
        }

        if ($sent === null) {
            return $this->finish($id, 'FAILED', 'MAIL_NOT_ACCEPTED');
        }

        return $this->finish($id, 'SENT');
    }

    private function finish(int $id, string $status, ?string $errorCode = null, ?string $previewPath = null): string
    {
        DB::table('application_status_emails')->where('id', $id)->where('status', 'PROCESSING')->update([
            'status' => $status, 'error_code' => $errorCode, 'preview_path' => $previewPath,
            'completed_at' => now(), 'updated_at' => now(),
        ]);

        return $status;
    }

    private function validEmail(string $email): bool
    {
        return strlen($email) <= 254 && ! preg_match('/[\r\n]/', $email)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function smtpConfigured(mixed $mailer): bool
    {
        if (! is_string($mailer) || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $mailer)) {
            return false;
        }

        $settings = config('mail.mailers.'.$mailer);
        $sender = config('mail.from.address');
        if (is_array($settings) && isset($settings['url'])) {
            try {
                $settings = (new ConfigurationUrlParser)->parseConfiguration($settings);
                $settings['transport'] = $settings['driver'] ?? null;
            } catch (Throwable) {
                return false;
            }
        }

        return ! config('mail.driver') && is_array($settings) && ($settings['transport'] ?? null) === 'smtp'
            && is_string($settings['host'] ?? null) && trim($settings['host']) !== ''
            && ! in_array(strtolower($settings['host']), ['127.0.0.1', 'localhost', '::1'], true)
            && filter_var($settings['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) !== false
            && is_numeric($settings['timeout'] ?? null) && $settings['timeout'] > 0 && $settings['timeout'] <= 10
            && is_string($sender) && $this->validEmail($sender) && $sender !== 'hello@example.com';
    }

    private function applicationUrl(int $applicationId): ?string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        $localHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && app()->environment(['local', 'testing'])
            && in_array(strtolower($parts['host'] ?? ''), ['127.0.0.1', 'localhost', '[::1]'], true);
        if (! is_array($parts) || ! filter_var($origin, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $origin)
            || (($parts['scheme'] ?? '') !== 'https' && ! $localHttp)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            return null;
        }

        return $origin.route('applications.show', ['application' => $applicationId], false);
    }
}
