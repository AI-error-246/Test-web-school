@extends('layouts.app')
@section('title', __('workspace.system_statistics'))
@section('content')
<div class="container"><h1>{{ __('workspace.system_statistics') }}</h1>
    <form class="panel grid grid-3" method="GET" action="{{ route('admin.statistics') }}"><label class="field">{{ __('workspace.start_inclusive') }}<input type="date" name="from" value="{{ $from->format('Y-m-d') }}" required></label><label class="field">{{ __('workspace.end_exclusive') }}<input type="date" name="to" value="{{ $to->format('Y-m-d') }}" required></label><button class="btn btn-primary">{{ __('workspace.view_statistics') }}</button></form>
    <p>{{ __('workspace.date_range_help') }}</p>
    <div class="stats"><div class="stat-card"><span>{{ __('workspace.new_users_period') }}</span><strong>{{ $newUsers }}</strong></div><div class="stat-card"><span>{{ __('workspace.applications_period') }}</span><strong>{{ $newApplications }}</strong></div><div class="stat-card"><span>{{ __('workspace.first_published_period') }}</span><strong>{{ $publishedJobs }}</strong></div><div class="stat-card"><span>{{ __('workspace.currently_accepting') }}</span><strong>{{ $acceptingJobs }}</strong></div></div>
    <p>{{ __('workspace.current_jobs_help') }}</p>
</div>
@endsection
