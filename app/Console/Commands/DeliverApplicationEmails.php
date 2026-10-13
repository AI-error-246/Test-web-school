<?php

namespace App\Console\Commands;

use App\Services\ApplicationStatusEmails;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('app:deliver-application-emails {--limit=50 : Maximum pending messages, from 1 to 500} {--retry-preview-failed : Retry failed previews only; never retries SMTP deliveries}')]
#[Description('Create private email previews or deliver explicitly enabled SMTP status notifications')]
class DeliverApplicationEmails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ApplicationStatusEmails $emails): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Limit must be an integer from 1 to 500.');

            return self::INVALID;
        }
        if (DB::transactionLevel() !== 0) {
            $this->error('Delivery requires committed data.');

            return self::FAILURE;
        }

        if ($this->option('retry-preview-failed')) {
            $failed = DB::table('application_status_emails')->where('mode', 'preview')->where('status', 'FAILED')
                ->whereIn('error_code', ['PREVIEW_WRITE_FAILED', 'INVALID_APP_URL'])->orderBy('id')->limit($limit)->pluck('id');
            DB::table('application_status_emails')->whereIn('id', $failed)->where('mode', 'preview')->where('status', 'FAILED')
                ->update(['status' => 'PENDING', 'error_code' => null, 'completed_at' => null, 'updated_at' => now()]);
        }

        $ids = DB::table('application_status_emails')->where('status', 'PENDING')->orderBy('id')->limit($limit)->pluck('id');
        $counts = [];
        foreach ($ids as $id) {
            try {
                $result = $emails->deliver((int) $id);
            } catch (Throwable) {
                $this->error('Delivery interrupted; inspect pending/processing state before any manual retry.');

                return self::FAILURE;
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        }

        $this->line('Processed '.count($ids).' message(s).');
        foreach ($counts as $status => $count) {
            $this->line($status.': '.$count);
        }

        return isset($counts['FAILED']) ? self::FAILURE : self::SUCCESS;
    }
}
