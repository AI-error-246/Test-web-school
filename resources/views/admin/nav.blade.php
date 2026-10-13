<nav class="panel" aria-label="{{ __('workspace.system_admin') }}">
    <a class="btn btn-secondary" href="{{ route('admin.dashboard') }}">{{ __('workspace.overview') }}</a>
    <a class="btn btn-secondary" href="{{ route('admin.users') }}">{{ __('workspace.accounts') }}</a>
    <a class="btn btn-secondary" href="{{ route('admin.jobs') }}">{{ __('workspace.moderate_jobs') }}</a>
    <a class="btn btn-secondary" href="{{ route('admin.reports') }}">{{ __('workspace.violation_reports') }}</a>
    <a class="btn btn-secondary" href="{{ route('admin.statistics') }}">{{ __('workspace.statistics') }}</a>
</nav>
