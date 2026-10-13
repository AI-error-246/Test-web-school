<?php

namespace App\Http\Controllers;

use App\Models\AccountStatusHistory;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use App\Services\JobWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(Request $request): View
    {
        $this->admin($request);

        return view('admin.dashboard', [
            'candidateCount' => User::where('role', 'candidate')->count(),
            'employerCount' => User::where('role', 'employer')->count(),
            'pendingCount' => JobPosting::where('moderation_state', 'PENDING')->count(),
            'reportCount' => Report::whereIn('status', ['OPEN', 'IN_REVIEW'])->count(),
            'pendingJobs' => JobPosting::with('company')->where('moderation_state', 'PENDING')->oldest()->limit(5)->get(),
        ]);
    }

    public function users(Request $request): View
    {
        $this->admin($request);
        $filters = $request->validate(['role' => ['nullable', Rule::in(['candidate', 'employer'])], 'status' => ['nullable', Rule::in(['ACTIVE', 'BLOCKED'])], 'q' => ['nullable', 'string', 'max:100']]);
        $query = User::whereIn('role', ['candidate', 'employer']);
        foreach (['role', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%'));
        }

        return view('admin.users', ['users' => $query->latest()->paginate(15)->withQueryString()]);
    }

    public function showUser(Request $request, User $user): View
    {
        $this->admin($request);
        abort_unless(in_array($user->role, ['candidate', 'employer'], true), 404);
        $user->load(['candidateProfile', 'company']);

        return view('admin.user', ['account' => $user,
            'history' => AccountStatusHistory::with('admin')->where('target_user_id', $user->id)->latest()->get(),
            'companyHistory' => $user->company ? ModerationAction::with('admin')->where('company_id', $user->company->id)->latest()->get() : collect(),
            'cvCount' => $user->candidateProfile?->cvs()->count() ?? 0,
        ]);
    }

    public function updateUserStatus(Request $request, User $user): RedirectResponse
    {
        $actor = $this->admin($request);
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'BLOCKED'])], 'current_status' => ['required', Rule::in(['ACTIVE', 'BLOCKED'])], 'reason' => ['required', 'string', 'max:2000']], $this->messages());
        DB::transaction(function () use ($user, $actor, $data): void {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($account->role, ['candidate', 'employer'], true), 404);
            abort_unless($account->status === $data['current_status'], 409, __('messages.status_changed'));
            if ($account->status === $data['status']) {
                throw ValidationException::withMessages(['status' => __('messages.choose_different_status')]);
            }
            AccountStatusHistory::create(['target_user_id' => $account->id, 'admin_id' => $actor->id, 'from_status' => $account->status, 'to_status' => $data['status'], 'reason' => $data['reason']]);
            $account->update(['status' => $data['status']]);
        });

        return back()->with('success', __('messages.account_status_saved'));
    }

    public function updateCompanyVisibility(Request $request, Company $company): RedirectResponse
    {
        $actor = $this->admin($request);
        $data = $request->validate(['visibility' => ['required', Rule::in(['VISIBLE', 'HIDDEN'])], 'current_visibility' => ['required', Rule::in(['VISIBLE', 'HIDDEN'])], 'reason' => ['required', 'string', 'max:2000']], $this->messages());
        DB::transaction(function () use ($actor, $company, $data): void {
            User::whereKey($company->owner_user_id)->lockForUpdate()->firstOrFail();
            $target = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            abort_unless($target->visibility === $data['current_visibility'], 409, __('messages.company_changed'));
            if ($target->visibility === $data['visibility']) {
                throw ValidationException::withMessages(['visibility' => __('messages.choose_different_status')]);
            }
            ModerationAction::create(['admin_id' => $actor->id, 'company_id' => $target->id,
                'action' => $data['visibility'] === 'HIDDEN' ? 'HIDE' : 'RESTORE', 'from_state' => $target->visibility,
                'to_state' => $data['visibility'], 'reason' => $data['reason']]);
            $target->update(['visibility' => $data['visibility']]);
        });

        return back()->with('success', __('messages.company_visibility_saved'));
    }

    public function jobs(Request $request): View
    {
        $this->admin($request);
        $data = $request->validate(['state' => ['nullable', Rule::in(['NOT_SUBMITTED', 'PENDING', 'APPROVED', 'REJECTED', 'HIDDEN'])]]);
        $query = JobPosting::with('company')->latest();
        if (! empty($data['state'])) {
            $query->where('moderation_state', $data['state']);
        }

        return view('admin.jobs', ['jobs' => $query->paginate(15)->withQueryString()]);
    }

    public function showJob(Request $request, JobPosting $job): View
    {
        $this->admin($request);

        return view('admin.job', ['job' => $job->load(['company.owner', 'category', 'location']),
            'history' => ModerationAction::with('admin')->where('job_id', $job->id)->latest()->get()]);
    }

    public function moderateJob(Request $request, JobPosting $job, JobWorkflow $workflow): RedirectResponse
    {
        $actor = $this->admin($request);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'hide', 'restore'])], 'version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']], $this->messages());
        DB::transaction(function () use ($actor, $job, $workflow, $data): void {
            $target = $workflow->lockJob($job->id);
            abort_unless($target->version === (int) $data['version'], 409, __('messages.review_content_changed'));
            $before = $target->moderation_state;
            $expected = ['approve' => 'PENDING', 'reject' => 'PENDING', 'hide' => 'APPROVED', 'restore' => 'HIDDEN'];
            if ($before !== $expected[$data['action']]) {
                throw ValidationException::withMessages(['action' => __('messages.action_invalid_status')]);
            }
            $next = ['approve' => 'APPROVED', 'reject' => 'REJECTED', 'hide' => 'HIDDEN', 'restore' => 'PENDING'][$data['action']];
            if ($data['action'] === 'approve') {
                $company = $target->company;
                if ($company->visibility !== 'VISIBLE' || $company->owner->status !== 'ACTIVE'
                    || $target->deadline_at === null || ! $target->deadline_at->isFuture()) {
                    throw ValidationException::withMessages(['action' => __('messages.cannot_approve_job')]);
                }
                if ($target->publication_state !== 'CLOSED') {
                    $target->publication_state = 'OPEN';
                }
                $target->published_at ??= now();
            }
            $target->moderation_state = $next;
            $target->save();
            ModerationAction::create(['admin_id' => $actor->id, 'job_id' => $target->id,
                'action' => strtoupper($data['action']), 'from_state' => $before, 'to_state' => $next,
                'reason' => $data['reason'], 'reviewed_version' => $target->version]);
        });

        return back()->with('success', __('messages.moderation_saved'));
    }

    public function reports(Request $request): View
    {
        $this->admin($request);
        $data = $request->validate(['status' => ['nullable', Rule::in(['OPEN', 'IN_REVIEW', 'RESOLVED', 'DISMISSED'])]]);
        $query = Report::with(['job', 'reporter'])->latest();
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('admin.reports', ['reports' => $query->paginate(15)->withQueryString()]);
    }

    public function showReport(Request $request, Report $report): View
    {
        $this->admin($request);

        return view('admin.report', ['report' => $report->load(['job', 'reporter', 'resolver'])]);
    }

    public function updateReport(Request $request, Report $report): RedirectResponse
    {
        $actor = $this->admin($request);
        $data = $request->validate(['status' => ['required', Rule::in(['IN_REVIEW', 'RESOLVED', 'DISMISSED'])], 'current_status' => ['required', Rule::in(['OPEN', 'IN_REVIEW'])], 'resolution_note' => ['nullable', 'required_if:status,RESOLVED,DISMISSED', 'string', 'max:4000']], $this->messages());
        DB::transaction(function () use ($actor, $report, $data): void {
            $target = Report::whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_unless($target->status === $data['current_status'], 409, __('messages.report_changed'));
            $allowed = ['OPEN' => ['IN_REVIEW'], 'IN_REVIEW' => ['RESOLVED', 'DISMISSED']];
            if (! in_array($data['status'], $allowed[$target->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('messages.review_report_first')]);
            }
            $target->status = $data['status'];
            if ($data['status'] === 'IN_REVIEW') {
                $target->assigned_admin_id = $actor->id;
            } else {
                $target->resolved_by = $actor->id;
                $target->resolved_at = now();
                $target->resolution_note = $data['resolution_note'];
            }
            $target->save();
        });

        return back()->with('success', __('messages.report_handling_saved'));
    }

    public function statistics(Request $request): View
    {
        $this->admin($request);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $from = CarbonImmutable::parse($data['from'] ?? now('Asia/Ho_Chi_Minh')->startOfMonth()->format('Y-m-d'), 'Asia/Ho_Chi_Minh')->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now('Asia/Ho_Chi_Minh')->addDay()->format('Y-m-d'), 'Asia/Ho_Chi_Minh')->startOfDay();
        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['to' => __('messages.end_after_start')]);
        }

        return view('admin.statistics', ['from' => $from, 'to' => $to,
            'newUsers' => User::where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->count(),
            'newApplications' => JobApplication::where('applied_at', '>=', $from->utc())->where('applied_at', '<', $to->utc())->count(),
            'publishedJobs' => JobPosting::where('published_at', '>=', $from->utc())->where('published_at', '<', $to->utc())->count(),
            'acceptingJobs' => JobPosting::accepting()->count(),
        ]);
    }

    private function admin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->role === 'admin' && $actor->status === 'ACTIVE', 403);

        return $actor;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['required' => __('messages.required'), 'required_if' => __('messages.resolution_required'), 'max' => __('messages.too_long'), 'in' => __('messages.invalid_choice')];
    }
}
