<?php

namespace App\Services;

use App\Models\Company;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class JobWorkflow
{
    public function lockJob(int $id): JobPosting
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lockJob requires an active transaction.');
        }

        $reference = JobPosting::query()->findOrFail($id);
        $companyReference = Company::query()->findOrFail($reference->company_id);
        $owner = User::query()->lockForUpdate()->findOrFail($companyReference->owner_user_id);
        $company = Company::query()->lockForUpdate()->findOrFail($companyReference->id);
        $job = JobPosting::query()->lockForUpdate()->findOrFail($id);

        abort_unless($company->owner_user_id === $owner->id && $job->company_id === $company->id, 409, __('messages.job_data_changed'));
        $company->setRelation('owner', $owner);
        $job->setRelation('company', $company);

        return $job;
    }

    public function assertCanReceive(JobPosting $job): void
    {
        if ($job->publication_state !== 'OPEN' || $job->moderation_state !== 'APPROVED'
            || ! $job->deadline_at || ! $job->deadline_at->isFuture()
            || $job->company->visibility !== 'VISIBLE' || $job->company->owner->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['job' => __('messages.job_not_accepting')]);
        }
    }
}
