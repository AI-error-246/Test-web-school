<aside class="sidebar" aria-label="{{ __('workspace.employer_area') }}">
    <h2>{{ __('workspace.employer') }}</h2>
    <nav>
        <a href="{{ route('employer.dashboard') }}" @if(request()->routeIs('employer.dashboard')) aria-current="page" @endif>{{ __('workspace.overview') }}</a>
        <a href="{{ route('employer.company') }}" @if(request()->routeIs('employer.company*')) aria-current="page" @endif>{{ __('workspace.company_profile') }}</a>
        <a href="{{ route('employer.jobs') }}" @if(request()->routeIs('employer.jobs*', 'employer.applications*')) aria-current="page" @endif>{{ __('workspace.jobs_candidates') }}</a>
    </nav>
    <p class="sidebar-note">{{ __('workspace.employer_nav_help') }}</p>
</aside>
