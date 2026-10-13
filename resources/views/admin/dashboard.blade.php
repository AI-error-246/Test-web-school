@extends('layouts.app')
@section('title', __('workspace.admin_overview'))
@section('content')
<div class="container">
    <h1>{{ __('workspace.admin_overview') }}</h1><p>{{ __('workspace.admin_overview_help') }}</p>

    <div class="stats">
        <div class="stat-card"><span>{{ __('workspace.candidate') }}</span><strong>{{ $candidateCount }}</strong></div>
        <div class="stat-card"><span>{{ __('workspace.employer') }}</span><strong>{{ $employerCount }}</strong></div>
        <div class="stat-card"><span>{{ __('workspace.pending_jobs') }}</span><strong>{{ $pendingCount }}</strong></div>
        <div class="stat-card"><span>{{ __('workspace.unclosed_reports') }}</span><strong>{{ $reportCount }}</strong></div>
    </div>
    <section class="panel"><h2>{{ __('workspace.oldest_pending_jobs') }}</h2>
        @forelse($pendingJobs as $job)
            <p><a href="{{ route('admin.jobs.show', $job) }}">{{ $job->title }}</a> · {{ $job->company->name }}</p>
        @empty<p>{{ __('workspace.no_pending_jobs') }}</p>@endforelse
    </section>
</div>
@endsection
