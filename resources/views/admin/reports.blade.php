@extends('layouts.app')
@section('title', __('workspace.violation_reports'))
@section('content')
<div class="container"><h1>{{ __('workspace.violation_reports') }}</h1>
    <form class="panel" method="GET" action="{{ route('admin.reports') }}"><label class="field">{{ __('workspace.status') }}<select name="status"><option value="">{{ __('workspace.all') }}</option>@foreach(['OPEN' => __('workspace.new'), 'IN_REVIEW' => __('workspace.reviewing'), 'RESOLVED' => __('workspace.resolved'), 'DISMISSED' => __('workspace.insufficient_evidence')] as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-primary">{{ __('workspace.filter_reports') }}</button></form>
    <div class="panel table-wrap"><table class="data-table"><thead><tr><th>{{ __('workspace.reason') }}</th><th>{{ __('workspace.job_postings') }}</th><th>{{ __('workspace.sender') }}</th><th>{{ __('workspace.status') }}</th></tr></thead><tbody>@forelse($reports as $report)<tr><td><a href="{{ route('admin.reports.show', $report) }}">{{ $report->reason }}</a></td><td>{{ $report->job->title }}</td><td>{{ $report->reporter->name }}</td><td>{{ (['OPEN' => __('workspace.new'), 'IN_REVIEW' => __('workspace.reviewing'), 'RESOLVED' => __('workspace.resolved'), 'DISMISSED' => __('workspace.insufficient_evidence')][$report->status] ?? __('status.'.$report->status)) }}</td></tr>@empty<tr><td colspan="4">{{ __('workspace.no_matching_reports') }}</td></tr>@endforelse</tbody></table></div>{{ $reports->links() }}
</div>
@endsection
