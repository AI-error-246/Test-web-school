<?php

namespace App\Http\Controllers;

use App\Models\ApplicationStatusHistory;
use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function create(Request $request, int $job): View|RedirectResponse
    {
        $record = JobPosting::accepting()->with('company')->findOrFail($job);
        $existing = JobApplication::where('candidate_id', $request->user()->id)->where('job_id', $job)->first();
        if ($existing) {
            return redirect()->route('applications.show', $existing);
        }

        return view('candidate.apply', ['job' => $record, 'profile' => $request->user()->candidateProfile, 'cvs' => CvDocument::where('candidate_id', $request->user()->id)->whereNull('archived_at')->get()]);
    }

    public function store(Request $request, int $job, JobWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['cv_id' => 'required|integer', 'consent' => 'accepted']);
        $application = DB::transaction(function () use ($request, $job, $data, $workflow): JobApplication {
            $candidate = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($candidate->status === 'ACTIVE' && $candidate->role === 'candidate', 403);
            $record = $workflow->lockJob($job);
            $existing = JobApplication::where('candidate_id', $candidate->id)->where('job_id', $record->id)->first();
            if ($existing) {
                return $existing;
            }
            $workflow->assertCanReceive($record);
            $profile = $candidate->candidateProfile;
            foreach (['full_name', 'phone', 'university', 'major'] as $field) {
                if (! $profile || ! filled($profile->$field)) {
                    throw ValidationException::withMessages(['profile' => __('messages.complete_profile_first')]);
                }
            }
            $cv = CvDocument::where('candidate_id', $candidate->id)->whereNull('archived_at')->find($data['cv_id']);
            if (! $cv) {
                throw ValidationException::withMessages(['cv_id' => __('messages.cv_unavailable')]);
            }
            $application = JobApplication::create(['job_id' => $record->id, 'candidate_id' => $candidate->id, 'cv_id' => $cv->id, 'name_snapshot' => $profile->full_name, 'email_snapshot' => $candidate->email, 'phone_snapshot' => $profile->phone, 'university_snapshot' => $profile->university, 'major_snapshot' => $profile->major, 'job_title_snapshot' => $record->title, 'company_name_snapshot' => $record->company->name, 'status' => 'SUBMITTED', 'applied_at' => now(), 'version' => 1]);
            ApplicationStatusHistory::create(['application_id' => $application->id, 'changed_by' => $candidate->id, 'from_status' => null, 'to_status' => 'SUBMITTED', 'changed_at' => now()]);

            return $application;
        });

        return redirect()->route('applications.show', $application)->with('success', __('messages.application_received'));
    }

    public function index(Request $request): View
    {
        return view('candidate.applications', ['applications' => JobApplication::where('candidate_id', $request->user()->id)->latest('applied_at')->paginate(12)]);
    }

    public function show(Request $request, int $application): View
    {
        $record = JobApplication::with(['job.company', 'cv', 'history.actor'])->findOrFail($application);
        $user = $request->user();
        abort_unless(($user->role === 'candidate' && $record->candidate_id === $user->id) || ($user->role === 'employer' && $record->job->company->owner_user_id === $user->id), 404);

        $note = $user->role === 'employer'
            ? DB::table('employer_application_notes')->where('application_id', $record->id)->where('employer_id', $user->id)->first()
            : null;

        return view('candidate.application', ['application' => $record, 'employerNote' => $note]);
    }
}
