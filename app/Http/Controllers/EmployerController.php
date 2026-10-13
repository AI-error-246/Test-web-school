<?php

namespace App\Http\Controllers;

use App\Models\ApplicationStatusHistory;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use App\Services\ApplicationStatusEmails;
use App\Services\JobWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployerController extends Controller
{
    public function __construct(private JobWorkflow $workflow) {}

    public function dashboard(Request $request): View
    {
        $user = $this->employer($request);
        $company = $user->company;
        $jobs = JobPosting::query()->where('company_id', $company?->id ?? 0);
        $filters = $request->validate($this->dateRules($request));
        $applications = JobApplication::query()->whereIn('job_id', (clone $jobs)->select('id'));
        $this->applyDates($applications, $filters);
        $statusCounts = (clone $applications)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('employer.dashboard', [
            'company' => $company,
            'openCount' => (clone $jobs)->accepting()->count(),
            'pendingCount' => (clone $jobs)->where('moderation_state', 'PENDING')->count(),
            'applicationCount' => $statusCounts->sum(),
            'newCount' => $statusCounts->get('SUBMITTED', 0),
            'statusCounts' => $statusCounts,
            'jobs' => $jobs->withCount('applications')->latest()->limit(5)->get(),
        ]);
    }

    public function company(Request $request): View
    {
        return view('employer.company', ['company' => $this->employer($request)->company]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $user = $this->employer($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:254'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ], $this->messages());
        unset($data['logo']);
        $newLogo = $request->file('logo')?->store('companies', 'public');
        if ($newLogo === false) {
            throw ValidationException::withMessages(['logo' => __('messages.logo_save_failed')]);
        }

        try {
            $oldLogo = DB::transaction(function () use ($user, $data, $newLogo): ?string {
                $owner = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($owner->status === 'ACTIVE' && $owner->role === 'employer', 403);
                $company = Company::query()->where('owner_user_id', $owner->id)->lockForUpdate()->first();
                $oldLogo = $company?->logo_key;
                $company ??= new Company(['owner_user_id' => $owner->id, 'visibility' => 'VISIBLE']);
                $company->fill($data);
                if ($newLogo) {
                    $company->logo_key = $newLogo;
                }
                $company->save();

                return $oldLogo;
            });
        } catch (\Throwable $exception) {
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }
            throw $exception;
        }
        if ($newLogo && $oldLogo && $oldLogo !== $newLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        return to_route('employer.company')->with('success', __('messages.company_profile_saved'));
    }

    public function jobs(Request $request): View
    {
        $company = $this->employer($request)->company;
        $filters = $request->validate(['state' => ['nullable', Rule::in(['DRAFT', 'OPEN', 'CLOSED'])]]);

        return view('employer.jobs', [
            'company' => $company,
            'jobs' => JobPosting::query()->where('company_id', $company?->id ?? 0)
                ->when($filters['state'] ?? null, fn ($query, $state) => $query->where('publication_state', $state))
                ->withCount('applications')->latest()->paginate(10)->withQueryString(),
        ]);
    }

    public function createJob(Request $request): View|RedirectResponse
    {
        $company = $this->employer($request)->company;
        if (! $company) {
            return to_route('employer.company')->with('success', __('messages.company_profile_first'));
        }

        return $this->jobForm(new JobPosting(['work_mode' => 'ONSITE', 'currency' => 'VND']), $company);
    }

    public function storeJob(Request $request): RedirectResponse
    {
        $user = $this->employer($request);
        $data = $this->jobData($request);
        $job = DB::transaction(function () use ($user, $data): JobPosting {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($owner->status === 'ACTIVE' && $owner->role === 'employer', 403);
            $company = Company::query()->where('owner_user_id', $owner->id)->lockForUpdate()->firstOrFail();
            if ($company->visibility !== 'VISIBLE') {
                throw ValidationException::withMessages(['company' => __('messages.hidden_company_create')]);
            }

            return JobPosting::query()->create([...$data, 'company_id' => $company->id, 'publication_state' => 'DRAFT', 'moderation_state' => 'NOT_SUBMITTED', 'version' => 1]);
        });

        return to_route('employer.jobs.edit', $job)->with('success', __('messages.draft_saved'));
    }

    public function editJob(Request $request, JobPosting $job): View
    {
        $this->ownedJob($request, $job);
        $this->assertEditable($job);

        return $this->jobForm($job, $job->company);
    }

    public function updateJob(Request $request, JobPosting $job): RedirectResponse
    {
        $this->ownedJob($request, $job);
        $data = $this->jobData($request);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        DB::transaction(function () use ($request, $job, $data, $version): void {
            $locked = $this->workflow->lockJob($job->id);
            $this->ownedJob($request, $locked);
            $this->assertVersion($locked->version, (int) $version);
            $this->assertEditable($locked);
            $locked->fill($data);
            if ($locked->publication_state === 'OPEN' || $locked->moderation_state === 'PENDING') {
                $this->assertReady($locked);
                $locked->moderation_state = 'PENDING';
            }
            $locked->version++;
            $locked->save();
        });

        return to_route('employer.jobs.edit', $job)->with('success', __('messages.job_changes_saved'));
    }

    public function submitJob(Request $request, JobPosting $job): RedirectResponse
    {
        $this->ownedJob($request, $job);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        DB::transaction(function () use ($request, $job, $version): void {
            $locked = $this->workflow->lockJob($job->id);
            $this->ownedJob($request, $locked);
            $this->assertVersion($locked->version, (int) $version);
            $this->assertEditable($locked);
            if (! in_array($locked->moderation_state, ['NOT_SUBMITTED', 'REJECTED'], true)) {
                throw ValidationException::withMessages(['job' => __('messages.job_already_submitted')]);
            }
            $this->assertReady($locked);
            $locked->moderation_state = 'PENDING';
            $locked->save();
        });

        return to_route('employer.jobs')->with('success', __('messages.job_sent_review'));
    }

    public function closeJob(Request $request, JobPosting $job): RedirectResponse
    {
        $this->ownedJob($request, $job);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        DB::transaction(function () use ($request, $job, $version): void {
            $locked = $this->workflow->lockJob($job->id);
            $this->ownedJob($request, $locked);
            $this->assertVersion($locked->version, (int) $version);
            if ($locked->publication_state !== 'OPEN') {
                throw ValidationException::withMessages(['job' => __('messages.only_open_can_close')]);
            }
            $locked->publication_state = 'CLOSED';
            $locked->save();
        });

        return to_route('employer.jobs')->with('success', __('messages.job_closed'));
    }

    public function applications(Request $request, JobPosting $job): View
    {
        $this->ownedJob($request, $job);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['SUBMITTED', 'REVIEWING', 'SELECTED', 'REJECTED'])],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            ...$this->dateRules($request),
        ]);
        $applications = $job->applications()->getQuery();
        $this->applyDates($applications, $filters);
        $keyword = trim($filters['q'] ?? '');
        if ($keyword !== '') {
            $applications->where(function ($query) use ($keyword): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($keyword)).'%';
                foreach (['name_snapshot', 'email_snapshot', 'university_snapshot'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        $direction = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        return view('employer.applications', ['job' => $job, 'applications' => $applications
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('applied_at', $direction)->orderBy('id', $direction)->paginate(12)->withQueryString()]);
    }

    /** @return array<string, array<int, string>> */
    private function dateRules(Request $request): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]];
    }

    /** @param array<string, mixed> $filters */
    private function applyDates(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where('applied_at', '>=', Carbon::createFromFormat('!Y-m-d', $filters['from'], 'Asia/Ho_Chi_Minh')->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('applied_at', '<', Carbon::createFromFormat('!Y-m-d', $filters['to'], 'Asia/Ho_Chi_Minh')->addDay()->utc());
        }
    }

    public function updateApplication(Request $request, JobApplication $application): RedirectResponse
    {
        $this->ownedJob($request, $application->job);
        $data = $request->validate(['status' => ['required', Rule::in(['REVIEWING', 'SELECTED', 'REJECTED'])], 'version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $application, $data): void {
            $job = $this->workflow->lockJob($application->job_id);
            $this->ownedJob($request, $job);
            $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($locked->job_id === $job->id, 409);
            $this->assertVersion($locked->version, (int) $data['version']);
            $allowed = ['SUBMITTED' => ['REVIEWING', 'REJECTED'], 'REVIEWING' => ['SELECTED', 'REJECTED']];
            if (! in_array($data['status'], $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('messages.invalid_application_transition')]);
            }
            $history = ApplicationStatusHistory::query()->create(['application_id' => $locked->id, 'changed_by' => $request->user()->id, 'from_status' => $locked->status, 'to_status' => $data['status'], 'changed_at' => now()]);
            $locked->status = $data['status'];
            $locked->version++;
            $locked->save();
            app(ApplicationStatusEmails::class)->enqueue($history);
        });

        return to_route('applications.show', $application)->with('success', __('messages.application_status_saved'));
    }

    private function employer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->role === 'employer' && $user->status === 'ACTIVE', 403);

        return $user;
    }

    private function ownedJob(Request $request, JobPosting $job): void
    {
        $user = $this->employer($request);
        abort_unless($job->company->owner_user_id === $user->id, 404);
        abort_unless($job->company->owner->status === 'ACTIVE', 403);
    }

    private function assertEditable(JobPosting $job): void
    {
        if ($job->publication_state === 'CLOSED' || $job->moderation_state === 'HIDDEN') {
            throw ValidationException::withMessages(['job' => __('messages.job_not_editable')]);
        }
    }

    private function assertVersion(int $current, int $submitted): void
    {
        abort_unless($current === $submitted, 409, __('messages.data_changed'));
    }

    private function assertReady(JobPosting $job): void
    {
        Validator::make($job->getAttributes(), [
            'title' => ['required'], 'category_id' => ['required'], 'location_id' => ['required'],
            'description' => ['required'], 'requirements' => ['required'], 'benefits' => ['required'],
            'work_mode' => ['required', Rule::in(['ONSITE', 'REMOTE', 'HYBRID'])], 'deadline_at' => ['required', 'date', 'after:now'],
        ], $this->messages())->validate();
        if ($job->company->visibility !== 'VISIBLE') {
            throw ValidationException::withMessages(['company' => __('messages.hidden_company_review')]);
        }
        Validator::make($job->company->getAttributes(), ['name' => ['required'], 'description' => ['required'], 'address' => ['required'], 'contact_email' => ['required', 'email']], $this->messages())->validate();
    }

    /** @return array<string, mixed> */
    private function jobData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:job_categories,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'work_mode' => ['required', Rule::in(['ONSITE', 'REMOTE', 'HYBRID'])],
            'description' => ['nullable', 'string', 'max:20000'], 'requirements' => ['nullable', 'string', 'max:20000'], 'benefits' => ['nullable', 'string', 'max:20000'],
            'deadline_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'allowance_min' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'allowance_max' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], $this->messages());
        if (isset($data['allowance_min'], $data['allowance_max']) && (float) $data['allowance_min'] > (float) $data['allowance_max']) {
            throw ValidationException::withMessages(['allowance_max' => __('messages.allowance_range')]);
        }
        if (! empty($data['deadline_at'])) {
            $data['deadline_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['deadline_at'], 'Asia/Ho_Chi_Minh')->setSecond(0)->utc();
        }
        $data['currency'] = 'VND';

        return $data;
    }

    private function jobForm(JobPosting $job, Company $company): View
    {
        return view('employer.job-form', ['job' => $job, 'company' => $company, 'categories' => JobCategory::query()->orderBy('name')->get(), 'locations' => Location::query()->orderBy('name')->get(), 'latestModeration' => $job->exists ? $job->moderationActions()->latest('created_at')->latest('id')->first() : null]);
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['required' => __('messages.fill_required'), 'max' => __('messages.over_limit'), 'email' => __('messages.valid_email'), 'url' => __('messages.valid_website'), 'exists' => __('messages.choice_gone'), 'after' => __('messages.deadline_future'), 'date_format' => __('messages.valid_datetime'), 'in' => __('messages.invalid_choice'), 'numeric' => __('messages.valid_number'), 'min' => __('messages.minimum_value'), 'mimes' => __('messages.logo_formats'), 'dimensions' => __('messages.logo_dimensions')];
    }
}
