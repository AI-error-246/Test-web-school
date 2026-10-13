@php
    $role = auth()->user()->role;
    $items = match ($role) {
        'candidate' => [
            ['candidate.dashboard', __('site.overview'), 'grid', request()->routeIs('candidate.dashboard')],
            ['candidate.profile.show', __('site.personal_profile'), 'user', request()->routeIs('candidate.profile*')],
            ['candidate.cvs', __('site.my_cvs'), 'file', request()->routeIs('candidate.cvs*')],
            ['candidate.saved-jobs', __('site.saved_jobs'), 'briefcase', request()->routeIs('candidate.saved-jobs*')],
            ['candidate.job-history', __('site.viewed_jobs'), 'clock', request()->routeIs('candidate.job-history*')],
            ['candidate.saved-searches', __('site.saved_filters'), 'search', request()->routeIs('candidate.saved-searches*')],
            ['candidate.notifications', __('site.notifications'), 'bell', request()->routeIs('candidate.notifications*')],
            ['candidate.applications', __('site.applications'), 'briefcase', request()->routeIs('candidate.applications','applications.show')],
            ['jobs.index', __('site.explore_opportunities'), 'search', false],
        ],
        'employer' => [
            ['employer.dashboard', __('site.overview'), 'grid', request()->routeIs('employer.dashboard')],
            ['employer.company', __('site.company_profile'), 'building', request()->routeIs('employer.company')],
            ['employer.jobs', __('site.jobs_applicants'), 'briefcase', request()->routeIs('employer.jobs','employer.jobs.edit','employer.applications','applications.show')],
            ['employer.jobs.create', __('site.create_job'), 'plus', request()->routeIs('employer.jobs.create')],
        ],
        default => [
            ['admin.dashboard', __('site.overview'), 'grid', request()->routeIs('admin.dashboard')],
            ['admin.users', __('site.candidates'), 'user', request()->routeIs('admin.users*') && request('role','candidate') === 'candidate', ['role'=>'candidate']],
            ['admin.users', __('site.employer'), 'building', request()->routeIs('admin.users*') && request('role') === 'employer', ['role'=>'employer']],
            ['admin.jobs', __('site.job_postings'), 'briefcase', request()->routeIs('admin.jobs*') && request('moderation_state') !== 'PENDING'],
            ['admin.jobs', __('site.pending_review'), 'clock', request()->routeIs('admin.jobs') && request('moderation_state') === 'PENDING', ['moderation_state'=>'PENDING']],
            ['admin.reports', __('site.reports'), 'flag', request()->routeIs('admin.reports*')],
            ['admin.statistics', __('site.statistics'), 'chart', request()->routeIs('admin.statistics')],
        ],
    };
@endphp
<nav class="workspace-nav" aria-label="{{ __('site.workspace_navigation', ['role' => $roleName]) }}">
    @foreach($items as [$routeName, $label, $icon, $active])
        <a href="{{ route($routeName, $items[$loop->index][4] ?? []) }}" @if($active) aria-current="page" @endif><x-icon :name="$icon"/><span>{{ $label }}</span>@if($active)<span class="nav-active-dot" aria-hidden="true"></span>@endif</a>
    @endforeach
</nav>
