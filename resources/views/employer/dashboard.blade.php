@extends('layouts.app')
@section('title', __('workspace.recruitment_overview'))
@section('content')
<div class="container workspace">

    <div class="workspace-main">
        <div class="page-header"><div><span class="eyebrow">{{ __('workspace.employer_workspace') }}</span><h1>{{ __('workspace.employer_hello', ['name' => auth()->user()->name]) }}</h1><p>{{ __('workspace.company_future', ['company' => $company?->name ?? __('workspace.your_company')]) }}</p></div><a class="btn btn-primary" href="{{ route('employer.jobs.create') }}">{{ __('workspace.create_job_plus') }}</a></div>
        @if(!$company)<div class="alert alert-info">{{ __('workspace.start_by') }} <a href="{{ route('employer.company') }}">{{ __('workspace.complete_company_link') }}</a> {{ __('workspace.company_intro_after') }}</div>@elseif($company->visibility === 'HIDDEN')<div class="alert alert-warning">{{ __('workspace.company_hidden_help') }}</div>@endif
        <form class="actions mb-3" method="get" action="{{ route('employer.dashboard') }}">
            <label for="from">{{ __('workspace.applications_from') }}</label><input style="width:auto" type="date" id="from" name="from" value="{{ request('from') }}">
            <label for="to">{{ __('workspace.through_date') }}</label><input style="width:auto" type="date" id="to" name="to" value="{{ request('to') }}">
            <button class="btn btn-secondary">{{ __('workspace.view_statistics') }}</button><a href="{{ route('employer.dashboard') }}">{{ __('workspace.all_time') }}</a>
        </form>
        <p class="muted">{{ __('workspace.employer_stats_help') }}</p>
        <div class="stats">
            <div class="stat-card"><p>{{ __('workspace.accepting') }}</p><strong>{{ $openCount }}</strong><small>{{ __('workspace.current_job_postings') }}</small></div>
            <div class="stat-card"><p>{{ __('workspace.awaiting_moderation') }}</p><strong>{{ $pendingCount }}</strong><small>{{ __('workspace.current_queue') }}</small></div>
            <div class="stat-card"><p>{{ __('workspace.received_applications') }}</p><strong>{{ $applicationCount }}</strong><small>{{ __('workspace.selected_date_range') }}</small></div>
            <div class="stat-card"><p>{{ __('workspace.new_applications') }}</p><strong>{{ $newCount }}</strong><small>{{ __('workspace.not_reviewed') }}</small></div>
        </div>
        <div class="stats">
            @foreach(['REVIEWING' => __('workspace.reviewing'), 'SELECTED' => __('workspace.chosen'), 'REJECTED' => __('workspace.not_suitable')] as $status => $label)
                <div class="stat-card"><p>{{ $label }}</p><strong>{{ $statusCounts->get($status, 0) }}</strong><small>{{ __('workspace.current_application_status') }}</small></div>
            @endforeach
        </div>
        <div class="section-header"><h2>{{ __('workspace.recent_jobs') }}</h2><a href="{{ route('employer.jobs') }}">{{ __('workspace.all_arrow') }}</a></div>
        @include('employer.job-table')
    </div>
</div>
@endsection
