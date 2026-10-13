<?php

namespace App\Services;

use App\Models\CvDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class CvSafety
{
    public function __construct(private CvMalwareScanner $scanner) {}

    /** @return array{scan_status: string, scan_sha256: string, scan_engine: string, scanned_at: Carbon} */
    public function scanBytes(string $body): array
    {
        abort_unless(str_starts_with($body, '%PDF-') && strlen($body) <= 5 * 1024 * 1024, 503, __('messages.stored_cv_invalid'));
        $disk = Storage::disk('local');
        abort_unless($disk->makeDirectory('quarantine'), 503, __('messages.cv_scan_area_unavailable'));
        $path = tempnam($disk->path('quarantine'), 'scan-');
        abort_unless($path !== false, 503, __('messages.cv_scan_file_unavailable'));
        try {
            abort_unless(file_put_contents($path, $body, LOCK_EX) === strlen($body), 503, __('messages.cv_scan_file_unavailable'));
            $result = $this->scanner->scan($path);
            $status = in_array($result['status'], ['CLEAN', 'INFECTED', 'ERROR'], true) ? $result['status'] : 'ERROR';

            return ['scan_status' => $status, 'scan_sha256' => hash('sha256', $body), 'scan_engine' => $result['engine'], 'scanned_at' => now()];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return array<string, mixed> */
    public function inspect(CvDocument $cv, string $body, bool $force = false): array
    {
        $sameBytes = $cv->scan_sha256 !== null && hash_equals($cv->scan_sha256, hash('sha256', $body));
        if (! $force && $sameBytes) {
            if ($cv->scan_status === 'INFECTED'
                || ($cv->scan_status === 'CLEAN' && $cv->scanned_at?->gt(now()->subDay()))
                || ($cv->scan_status === 'ERROR' && $cv->scanned_at?->gt(now()->subMinutes(5)))) {
                return ['scan_status' => $cv->scan_status];
            }
        }
        $result = $this->scanBytes($body);
        if ($cv->exists) {
            $cv->update($result);
        }

        return $result;
    }

    public function assertDownloadable(CvDocument $cv, string $body): void
    {
        $result = $this->inspect($cv, $body);
        abort_unless($result['scan_status'] === 'CLEAN', 503, __('messages.cv_download_blocked'));
    }
}
