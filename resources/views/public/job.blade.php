@extends('layouts.app')
@section('title',$job->title)
@section('content')
@if(auth()->user()?->role === 'candidate' && auth()->user()?->status === 'ACTIVE')
<div class="panel">
    <form action="{{ route($saved ? 'candidate.saved-jobs.destroy' : 'candidate.saved-jobs.store', $job) }}" method="post">
        @csrf @if($saved) @method('DELETE') @endif
        <button class="btn btn-secondary">{{ $saved ? __('site.unsave_job') : __('site.save_job') }}</button>
        <a href="{{ route('candidate.saved-jobs') }}">{{ __('site.view_saved_jobs') }}</a>
    </form>
    <p class="field-help" data-history-message>{{ __('site.history_note') }}</p>
</div>
@push('scripts')
<script>
fetch(@json(route('candidate.job-history.store', $job)), {
    method: 'POST', credentials: 'same-origin',
    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'}
}).then(response => { if (response.status !== 204 || response.redirected) throw new Error('history'); }).catch(() => {
    document.querySelector('[data-history-message]').textContent = @json(__('site.history_failed'));
});
</script>
@endpush
@endif
<div class="page-header"><a href="{{ route('jobs.index') }}">{{ __('site.back_jobs') }}</a><p class="eyebrow">{{ $job->category?->name }}</p><h1>{{ $job->title }}</h1><a href="{{ route('companies.show',$job->company) }}">{{ $job->company->name }}</a><div class="job-meta"><span>{{ $job->location?->name }}</span><span>{{ __('status.'.$job->work_mode) }}</span><span>{{ __('site.deadline', ['date' => $job->deadline_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i')]) }}</span></div></div>
<div class="detail-layout"><article class="panel prose"><h2>{{ __('site.description') }}</h2><p class="preserve-lines">{{ $job->description }}</p><h2>{{ __('site.requirements') }}</h2><p class="preserve-lines">{{ $job->requirements }}</p><h2>{{ __('site.benefits') }}</h2><p class="preserve-lines">{{ $job->benefits }}</p></article><aside class="detail-aside"><div class="panel"><h2>{{ __('site.ready_apply') }}</h2><p class="salary">@if($job->allowance_min!==null){{ number_format((float)$job->allowance_min,0,',','.') }}@if($job->allowance_max!==null) – {{ number_format((float)$job->allowance_max,0,',','.') }}@endif {{ __('site.vnd_month') }} @else {{ __('site.allowance_unpublished') }} @endif</p>
@if($existing)<a class="btn btn-secondary" href="{{ route('applications.show',$existing) }}">{{ __('site.view_application') }}</a>
@elseif($job->publication_state!=='OPEN' || $job->deadline_at?->isPast())<span class="badge badge-neutral">{{ __('site.no_longer_accepting') }}</span>
@elseif(!auth()->check())<a class="btn btn-primary" href="{{ route('candidate.apply',$job) }}">{{ __('site.login_apply') }}</a>
@elseif(auth()->user()->role==='candidate')<a class="btn btn-primary" href="{{ route('candidate.apply',$job) }}">{{ __('site.apply_now') }}</a>
@else<p>{{ __('site.candidate_only') }}</p>@endif
<p class="field-help">{{ __('site.application_privacy') }}</p></div><div class="panel"><h3>{{ __('site.about_company') }}</h3><p>{{ $job->company->name }}</p><p>{{ $job->company->address }}</p><a href="{{ route('companies.show',$job->company) }}">{{ __('site.view_company') }}</a></div></aside></div>
<x-company-map :company="$job->company" />
@if(auth()->check() && in_array(auth()->user()->role,['candidate','employer'],true))<details class="panel"><summary>{{ __('site.report_job') }}</summary><form action="{{ route('reports.store',$job) }}" method="post">@csrf<label class="field">{{ __('site.reason') }}<input name="reason" required maxlength="200"></label><label class="field">{{ __('site.report_description') }}<textarea name="description" rows="3" required maxlength="4000"></textarea></label><button class="btn btn-secondary">{{ __('site.submit_report') }}</button></form></details>@endif
@endsection
