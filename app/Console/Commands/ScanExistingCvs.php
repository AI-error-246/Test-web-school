<?php

namespace App\Console\Commands;

use App\Models\CvDocument;
use App\Services\CvSafety;
use App\Services\CvStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:scan-existing-cvs {--force : Scan again even if the previous result is fresh}')]
#[Description('Quét CV riêng tư hiện có, giữ nguyên tệp và chỉ cập nhật trạng thái kiểm tra')]
class ScanExistingCvs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CvStorage $storage, CvSafety $safety): int
    {
        $blocked = 0;
        foreach (CvDocument::query()->orderBy('id')->lazyById(50) as $cv) {
            try {
                $result = $safety->inspect($cv, $storage->contents($cv), (bool) $this->option('force'));
                $status = $result['scan_status'];
            } catch (\Throwable) {
                $cv->update(['scan_status' => 'ERROR', 'scan_sha256' => null, 'scanned_at' => now()]);
                $status = 'ERROR';
            }
            $this->line('CV #'.$cv->id.': '.$status);
            $blocked += $status === 'CLEAN' ? 0 : 1;
        }
        $this->line('Blocked: '.$blocked);

        return $blocked === 0 ? self::SUCCESS : self::FAILURE;
    }
}
