<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\PublicCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicJobController extends Controller
{
    public function __construct(private PublicCatalog $catalog) {}

    public function home(): View
    {
        $categories = $this->catalog->categories();
        $locations = $this->catalog->locations();
        $jobs = $this->withCompanySummary(JobPosting::accepting())->orderByDesc('published_at')->orderByDesc('job_postings.id')->limit(6)->get();
        $this->attachCompanySummaries($jobs);
        $this->attachCatalogs($jobs, $categories, $locations);
        $counts = DB::query()
            ->selectSub(JobPosting::accepting()->selectRaw('COUNT(*)'), 'job_count')
            ->selectSub(Company::where('visibility', 'VISIBLE')->whereHas('owner', fn ($q) => $q->where('status', 'ACTIVE'))->selectRaw('COUNT(*)'), 'company_count')
            ->first();

        return view('public.home', ['demoUsers' => app()->environment('local') && config('app.demo_mode') ? User::where('is_demo', true)->where('status', 'ACTIVE')->orderBy('id')->get(['id', 'name', 'role']) : collect(), 'jobs' => $jobs, 'categories' => $categories, 'jobCount' => (int) $counts->job_count, 'companyCount' => (int) $counts->company_count]);
    }

    public function index(Request $request): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:120', 'category' => 'nullable|integer', 'location' => 'nullable|integer', 'mode' => 'nullable|in:ONSITE,REMOTE,HYBRID', 'sort' => 'nullable|in:newest,deadline']);
        $query = $this->withCompanySummary(JobPosting::accepting());
        if (! empty($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [mb_strtolower($term)])->orWhereHas('company', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [mb_strtolower($term)])));
        }
        foreach (['category' => 'category_id', 'location' => 'location_id', 'mode' => 'work_mode'] as $input => $column) {
            if (! empty($data[$input])) {
                $query->where($column, $data[$input]);
            }
        }
        $query->orderBy(($data['sort'] ?? 'newest') === 'deadline' ? 'deadline_at' : 'published_at', ($data['sort'] ?? 'newest') === 'deadline' ? 'asc' : 'desc')->orderByDesc('job_postings.id');

        $categories = $this->catalog->categories();
        $locations = $this->catalog->locations();
        $jobs = $query->paginate(9)->withQueryString();
        $this->attachCompanySummaries($jobs->getCollection());
        $this->attachCatalogs($jobs->getCollection(), $categories, $locations);

        return view('public.jobs', ['jobs' => $jobs, 'categories' => $categories, 'locations' => $locations]);
    }

    private function withCompanySummary(Builder $query): Builder
    {
        return $query->join('companies as public_company', 'public_company.id', '=', 'job_postings.company_id')
            ->select('job_postings.*', 'public_company.name as public_company_name', 'public_company.address as public_company_address');
    }

    private function attachCompanySummaries(Collection $jobs): void
    {
        foreach ($jobs as $job) {
            $job->setRelation('company', (new Company)->newFromBuilder([
                'id' => $job->company_id,
                'name' => $job->public_company_name,
                'address' => $job->public_company_address,
            ], $job->getConnectionName()));
            $job->offsetUnset('public_company_name');
            $job->offsetUnset('public_company_address');
        }
    }

    private function attachCatalogs(Collection $jobs, Collection $categories, Collection $locations): void
    {
        $categoriesById = $categories->keyBy('id');
        $locationsById = $locations->keyBy('id');
        foreach ($jobs as $job) {
            $job->setRelation('category', $categoriesById->get($job->category_id));
            $job->setRelation('location', $locationsById->get($job->location_id));
        }
    }

    public function show(int $job): View
    {
        $record = $this->withCompanySummary(JobPosting::publiclyVisible())->findOrFail($job);
        $records = new Collection([$record]);
        $this->attachCompanySummaries($records);
        $this->attachCatalogs($records, $this->catalog->categories(), $this->catalog->locations());

        $isCandidate = auth()->check() && auth()->user()->role === 'candidate' && auth()->user()->status === 'ACTIVE';

        return view('public.job', ['job' => $record, 'existing' => auth()->check() ? $record->applications()->where('candidate_id', auth()->id())->first() : null, 'saved' => $isCandidate && DB::table('candidate_saved_jobs')->where('candidate_id', auth()->id())->where('job_id', $record->id)->exists()]);
    }

    public function company(int $company): View
    {
        $record = Company::where('visibility', 'VISIBLE')->whereHas('owner', fn ($q) => $q->where('status', 'ACTIVE'))->findOrFail($company);
        $jobs = JobPosting::accepting()->where('company_id', $record->id)->paginate(9);
        $jobs->getCollection()->each(fn (JobPosting $job) => $job->setRelation('company', $record));
        $this->attachCatalogs($jobs->getCollection(), $this->catalog->categories(), $this->catalog->locations());

        return view('public.company', ['company' => $record, 'jobs' => $jobs]);
    }

    public function logo(int $company): StreamedResponse
    {
        $record = Company::findOrFail($company);
        $privileged = auth()->check() && auth()->user()->status === 'ACTIVE' && (auth()->id() === $record->owner_user_id || auth()->user()->role === 'admin');
        abort_unless($privileged || ($record->visibility === 'VISIBLE' && $record->owner->status === 'ACTIVE'), 404);
        abort_unless($record->logo_key && Storage::disk('public')->exists($record->logo_key), 404);

        return Storage::disk('public')->response($record->logo_key, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
