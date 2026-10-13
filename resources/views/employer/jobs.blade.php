@extends('layouts.app')
@section('title', __('workspace.manage_jobs'))
@section('content')
<div class="container workspace">

    <div class="workspace-main">
        <div class="page-header"><div><span class="eyebrow">{{ __('workspace.connect_talent') }}</span><h1>{{ __('workspace.job_postings') }}</h1><p>{{ __('workspace.manage_jobs_help') }}</p></div><a class="btn btn-primary" href="{{ route('employer.jobs.create') }}">{{ __('workspace.create_new_job') }}</a></div>
        <form method="get" action="{{ route('employer.jobs') }}" class="actions mb-3"><label for="state">{{ __('workspace.posting_status') }}</label><select id="state" name="state" style="width:auto"><option value="">{{ __('workspace.all_statuses') }}</option><option value="DRAFT" @selected(request('state') === 'DRAFT')>{{ __('workspace.draft') }}</option><option value="OPEN" @selected(request('state') === 'OPEN')>{{ __('workspace.open') }}</option><option value="CLOSED" @selected(request('state') === 'CLOSED')>{{ __('workspace.closed') }}</option></select><button class="btn btn-secondary">{{ __('workspace.filter_jobs') }}</button></form>
        @include('employer.job-table')
        {{ $jobs->links() }}
    </div>
</div>
@endsection
