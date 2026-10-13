<?php

namespace App\Services;

use App\Models\CvDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CvStorage
{
    /** @return array{disk: string, key: string} */
    public function store(UploadedFile $file): array
    {
        $disk = (string) config('supabase.cv_disk', 'local');
        $key = 'cvs/'.Str::uuid().'.pdf';
        if ($disk === 'local') {
            $stored = Storage::disk('local')->putFileAs('cvs', $file, basename($key));
            abort_unless($stored, 503, __('messages.cv_save_failed'));
        } elseif ($disk === 'supabase') {
            $body = file_get_contents($file->getRealPath());
            abort_unless(is_string($body), 503, __('messages.cv_read_failed'));
            try {
                $result = $this->client((string) config('supabase.secret_key'))
                    ->withHeaders(['x-upsert' => 'false', 'Cache-Control' => 'no-store'])
                    ->withBody($body, 'application/pdf')->post($this->url('/object/'.$this->bucket().'/'.$key));
            } catch (ConnectionException) {
                abort(503, __('messages.cv_cloud_unavailable'));
            }
            abort_unless($result->successful(), 503, __('messages.cv_cloud_save_unconfirmed'));
        } else {
            abort(503, __('messages.cv_storage_invalid'));
        }

        return ['disk' => $disk, 'key' => $key];
    }

    public function delete(string $disk, string $key): void
    {
        $this->validateKey($key);
        abort_unless(preg_match('/^cvs\/[0-9a-f-]{36}\.pdf$/D', $key), 503, __('messages.cv_rollback_new_only'));
        if ($disk === 'local') {
            abort_unless(Storage::disk('local')->delete($key), 503, __('messages.cv_rollback_failed'));

            return;
        }
        abort_unless($disk === 'supabase', 503, __('messages.cv_storage_invalid'));
        try {
            $result = $this->client((string) config('supabase.secret_key'))->asJson()
                ->delete($this->url('/object/'.$this->bucket()), ['prefixes' => [$key]]);
        } catch (ConnectionException) {
            abort(503, __('messages.cv_cloud_rollback_failed'));
        }
        abort_unless($result->successful(), 503, __('messages.cv_cloud_rollback_failed'));
    }

    /** Call only after the controller has authorized the CV reader. */
    public function response(CvDocument $cv): Response
    {
        $body = $this->contents($cv);
        app(CvSafety::class)->assertDownloadable($cv, $body);
        $headers = ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="cv-'.$cv->id.'.pdf"', 'Content-Security-Policy' => "sandbox; default-src 'none'"];
        if (($cv->disk ?? 'local') === 'local') {
            return new StreamedResponse(static function () use ($body): void {
                echo $body;
            }, 200, $headers);
        }

        return new Response($body, 200, $headers);
    }

    /** Reads private bytes; never expose them without authorization and a clean scan. */
    public function contents(CvDocument $cv): string
    {
        $disk = $cv->disk ?? 'local';
        $key = $cv->storage_key;
        $this->validateKey($key);
        if ($disk === 'local') {
            abort_unless(Storage::disk('local')->exists($key), 404);

            $body = Storage::disk('local')->get($key);
            abort_unless(is_string($body) && str_starts_with($body, '%PDF-') && strlen($body) <= 5 * 1024 * 1024, 503, __('messages.stored_cv_invalid'));

            return $body;
        }
        abort_unless($disk === 'supabase', 503, __('messages.cv_storage_invalid'));
        try {
            $result = $this->client((string) config('supabase.secret_key'))
                ->get($this->url('/object/authenticated/'.$this->bucket().'/'.$key));
        } catch (ConnectionException) {
            abort(503, __('messages.cv_cloud_download_failed'));
        }
        abort_if($result->status() === 404, 404);
        abort_unless($result->successful(), 503, __('messages.cv_cloud_download_failed'));
        $body = $result->body();
        abort_unless(str_starts_with($body, '%PDF-') && strlen($body) <= 5 * 1024 * 1024, 503, __('messages.stored_cv_invalid'));

        return $body;
    }

    private function client(#[SensitiveParameter] string $secretKey): PendingRequest
    {
        abort_unless(preg_match('/^sb_secret_[A-Za-z0-9_-]+$/D', $secretKey), 503, __('messages.supabase_server_key_invalid'));

        return Http::withHeaders(['apikey' => $secretKey])->connectTimeout(3)->timeout(20)->withoutRedirecting();
    }

    private function url(string $path): string
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $parts = parse_url($url);
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && empty($parts['path']), 503, __('messages.supabase_url_invalid'));

        return $url.'/storage/v1'.$path;
    }

    private function bucket(): string
    {
        $bucket = (string) config('supabase.storage_bucket', 'internship-cvs');
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $bucket), 503, __('messages.cv_bucket_invalid'));

        return $bucket;
    }

    private function validateKey(string $key): void
    {
        abort_unless(preg_match('/^cvs\/(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_-]+\.pdf$/D', $key), 503, __('messages.cv_path_invalid'));
    }
}
