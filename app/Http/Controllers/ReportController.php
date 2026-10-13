<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\Report;
use App\Models\User;
use App\Services\JobWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function store(Request $request, JobPosting $job, JobWorkflow $workflow): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && in_array($actor->role, ['candidate', 'employer'], true) && $actor->status === 'ACTIVE', 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:4000']], [
            'required' => __('messages.required'), 'max' => __('messages.too_long'),
        ]);
        DB::transaction(function () use ($actor, $job, $workflow, $data): void {
            $target = $workflow->lockJob($job->id);
            abort_unless(JobPosting::publiclyVisible()->whereKey($target->id)->exists(), 404);
            Report::create(['reporter_id' => $actor->id, 'job_id' => $target->id, 'reason' => $data['reason'], 'description' => $data['description'], 'status' => 'OPEN']);
        });

        return back()->with('success', __('messages.report_sent'));
    }
}
