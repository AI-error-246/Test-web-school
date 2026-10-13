@extends('layouts.app')
@section('title', __('workspace.moderation_detail'))
@section('content')
<div class="container"><h1>{{ $job->title }}</h1>
    <section class="panel"><p><a href="{{ route('admin.users.show', $job->company->owner) }}">{{ $job->company->name }}</a> · {{ $job->category?->name }} · {{ $job->location?->name }}</p>
        <p>{{ __('workspace.job_states', ['moderation' => __('status.'.$job->moderation_state), 'publication' => __('status.'.$job->publication_state), 'version' => $job->version]) }}</p>
        <p>{{ __('workspace.application_deadline', ['date' => $job->deadline_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') ?: __('workspace.not_set')]) }}</p>
        <h2>{{ __('workspace.description') }}</h2><div style="white-space:pre-wrap">{{ $job->description }}</div><h2>{{ __('workspace.requirements') }}</h2><div style="white-space:pre-wrap">{{ $job->requirements }}</div><h2>{{ __('workspace.benefits') }}</h2><div style="white-space:pre-wrap">{{ $job->benefits }}</div>
    </section>
    @if(in_array($job->moderation_state, ['PENDING', 'APPROVED', 'HIDDEN']))
    <form class="panel" method="POST" action="{{ route('admin.jobs.moderate', $job) }}">@csrf<input type="hidden" name="version" value="{{ $job->version }}"><h2>{{ __('workspace.moderation_result') }}</h2>
        <label class="field">{{ __('workspace.actions') }}<select name="action" required>@if($job->moderation_state === 'PENDING')<option value="approve">{{ __('workspace.approve_content') }}</option><option value="reject">{{ __('workspace.reject') }}</option>@elseif($job->moderation_state === 'APPROVED')<option value="hide">{{ __('workspace.hide_job') }}</option>@else<option value="restore">{{ __('workspace.return_to_pending') }}</option>@endif</select></label>
        <label class="field">{{ __('workspace.reason_note') }}<textarea name="reason" required maxlength="2000">{{ old('reason') }}</textarea></label><button class="btn btn-primary">{{ __('workspace.save_result') }}</button><p>{{ __('workspace.moderation_disclaimer') }}</p>
    </form>@endif
    <section class="panel"><h2>{{ __('workspace.moderation_history') }}</h2>@forelse($history as $event)<p>{{ $event->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · {{ __('status.'.$event->from_state) }} → {{ __('status.'.$event->to_state) }} · {{ $event->reason }} · {{ __('workspace.version', ['version' => $event->reviewed_version]) }} · {{ $event->admin->name }}</p>@empty<p>{{ __('workspace.no_moderation') }}</p>@endforelse</section>
</div>
@endsection
