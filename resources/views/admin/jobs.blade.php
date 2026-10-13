@extends('layouts.app')
@section('title', __('workspace.job_moderation'))
@section('content')
<div class="container"><h1>{{ __('workspace.job_moderation') }}</h1>
    <form class="panel" method="GET" action="{{ route('admin.jobs') }}"><label class="field">{{ __('workspace.moderation_status') }}<select name="state"><option value="">{{ __('workspace.all') }}</option>@foreach(['NOT_SUBMITTED' => __('workspace.not_sent'), 'PENDING' => __('workspace.pending'), 'APPROVED' => __('workspace.approved'), 'REJECTED' => __('workspace.reject'), 'HIDDEN' => __('workspace.currently_hidden')] as $value => $label)<option value="{{ $value }}" @selected(request('state') === $value)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-primary">{{ __('workspace.filter_jobs') }}</button></form>
    <div class="panel table-wrap"><table class="data-table"><thead><tr><th>{{ __('workspace.title') }}</th><th>{{ __('workspace.company') }}</th><th>{{ __('workspace.moderation') }}</th><th>{{ __('workspace.publication') }}</th><th>{{ __('workspace.version_label') }}</th></tr></thead><tbody>
        @forelse($jobs as $job)<tr><td><a href="{{ route('admin.jobs.show', $job) }}">{{ $job->title }}</a></td><td>{{ $job->company->name }}</td><td>{{ __('status.'.$job->moderation_state) }}</td><td>{{ __('status.'.$job->publication_state) }}</td><td>{{ $job->version }}</td></tr>@empty<tr><td colspan="5">{{ __('workspace.no_matching_jobs') }}</td></tr>@endforelse
    </tbody></table></div>{{ $jobs->links() }}
</div>
@endsection
