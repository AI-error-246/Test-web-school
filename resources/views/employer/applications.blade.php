@extends('layouts.app')
@section('title', __('workspace.candidate_applications'))
@section('content')
@php($statuses = ['SUBMITTED' => __('workspace.submitted'), 'REVIEWING' => __('workspace.reviewing'), 'SELECTED' => __('workspace.chosen'), 'REJECTED' => __('workspace.not_suitable')])
<div class="container workspace">

    <div class="workspace-main">
        <div class="page-header"><div><span class="eyebrow">{{ __('workspace.applications_by_position') }}</span><h1>{{ $job->title }}</h1><p>{{ __('workspace.application_management_help') }}</p></div><a class="btn btn-secondary" href="{{ route('employer.jobs') }}">{{ __('workspace.job_list') }}</a></div>
        <form class="panel grid-3 mb-3" method="get" action="{{ route('employer.applications', $job) }}">
            <div><label for="q">{{ __('workspace.name_email_university') }}</label><input id="q" name="q" maxlength="120" value="{{ request('q') }}"></div>
            <div><label for="status">{{ __('workspace.application_status') }}</label><select name="status" id="status"><option value="">{{ __('workspace.all_applications') }}</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="from">{{ __('workspace.submitted_from') }}</label><input type="date" id="from" name="from" value="{{ request('from') }}"></div>
            <div><label for="to">{{ __('workspace.through_date') }}</label><input type="date" id="to" name="to" value="{{ request('to') }}"></div>
            <div><label for="sort">{{ __('workspace.sort') }}</label><select id="sort" name="sort"><option value="newest" @selected(request('sort', 'newest') === 'newest')>{{ __('workspace.newest_first') }}</option><option value="oldest" @selected(request('sort') === 'oldest')>{{ __('workspace.oldest_first') }}</option></select></div>
            <div class="actions"><button class="btn btn-primary">{{ __('workspace.filter_applications') }}</button><a class="btn btn-secondary" href="{{ route('employer.applications', $job) }}">{{ __('workspace.clear_filters') }}</a></div>
        </form>
        <p class="muted">{{ __('workspace.application_results', ['count' => $applications->total()]) }}</p>
        @if($applications->isEmpty())<div class="empty-state"><h2>{{ __('workspace.no_matching_applications') }}</h2><p>{{ __('workspace.applications_empty_help') }}</p></div>@else
            <div class="table-wrap" tabindex="0" role="region" aria-label="{{ __('workspace.candidates_table_aria') }}"><table class="data-table"><thead><tr><th scope="col">{{ __('workspace.candidate') }}</th><th scope="col">{{ __('workspace.submitted_date') }}</th><th scope="col">{{ __('workspace.status') }}</th><th scope="col">{{ __('workspace.application') }}</th></tr></thead><tbody>@foreach($applications as $application)<tr><td><strong>{{ $application->name_snapshot }}</strong><small>{{ $application->university_snapshot }}</small></td><td class="nowrap">{{ $application->applied_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</td><td><span class="badge {{ $application->status === 'SELECTED' ? 'badge-success' : ($application->status === 'REJECTED' ? 'badge-danger' : 'badge-info') }}">{{ $statuses[$application->status] }}</span></td><td><a class="btn btn-secondary btn-small" href="{{ route('applications.show', $application) }}">{{ __('workspace.view_application') }}</a></td></tr>@endforeach</tbody></table></div>
            {{ $applications->links() }}
        @endif
    </div>
</div>
@endsection
