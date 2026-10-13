@extends('layouts.app')
@section('title', __('workspace.review_report'))
@section('content')
<div class="container"><h1>{{ __('workspace.report_number', ['id' => $report->id]) }}</h1>
    <section class="panel"><h2>{{ $report->reason }}</h2><p><a href="{{ route('admin.jobs.show', $report->job) }}">{{ $report->job->title }}</a></p><p>{{ __('workspace.report_sender', ['name' => $report->reporter->name]) }} · {{ $report->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</p><div style="white-space:pre-wrap">{{ $report->description }}</div><p>{{ __('workspace.report_status', ['status' => (['OPEN' => __('workspace.new'), 'IN_REVIEW' => __('workspace.reviewing'), 'RESOLVED' => __('workspace.resolved'), 'DISMISSED' => __('workspace.insufficient_evidence')][$report->status] ?? __('status.'.$report->status))]) }}</p></section>
    @if(in_array($report->status, ['OPEN', 'IN_REVIEW']))
        <form class="panel" method="POST" action="{{ route('admin.reports.update', $report) }}">@csrf @method('PATCH')<input type="hidden" name="current_status" value="{{ $report->status }}">
            @if($report->status === 'OPEN')<input type="hidden" name="status" value="IN_REVIEW"><button class="btn btn-primary">{{ __('workspace.accept_report') }}</button>
            @else<label class="field">{{ __('workspace.result') }}<select name="status"><option value="RESOLVED">{{ __('workspace.resolved') }}</option><option value="DISMISSED">{{ __('workspace.insufficient_evidence') }}</option></select></label><label class="field">{{ __('workspace.conclusion_required') }}<textarea name="resolution_note" required maxlength="4000">{{ old('resolution_note') }}</textarea></label><button class="btn btn-primary">{{ __('workspace.finish_report') }}</button>@endif
            <p>{{ __('workspace.hide_report_help') }}</p>
        </form>
    @else<section class="panel"><h2>{{ __('workspace.conclusion') }}</h2><p>{{ $report->resolution_note }}</p><p>{{ $report->resolver?->name }} · {{ $report->resolved_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</p></section>@endif
</div>
@endsection
