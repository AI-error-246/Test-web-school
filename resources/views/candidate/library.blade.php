@extends('layouts.app')
@section('title', $history ? __('workspace.viewed_jobs') : __('workspace.saved_jobs'))
@section('content')
<div class="page-header"><p class="eyebrow">{{ __('workspace.candidate_workspace') }}</p><h1>{{ $history ? __('workspace.viewed_jobs') : __('workspace.saved_jobs') }}</h1><p>{{ $history ? __('workspace.viewed_jobs_help') : __('workspace.saved_jobs_help') }} {{ __('workspace.hidden_jobs_help') }}</p></div>
<div class="section-header"><a class="btn btn-secondary" href="{{ route('jobs.index') }}">{{ __('workspace.explore') }}</a>
@if($history)<form method="post" action="{{ route('candidate.job-history.clear') }}">@csrf @method('DELETE')<button class="btn btn-secondary">{{ __('workspace.clear_history') }}</button></form>@endif</div>
<div class="grid grid-2">
@forelse($jobs as $job)
<article class="panel"><p class="eyebrow">{{ $job->company->name }}</p><h2><a href="{{ route('jobs.show', $job->id) }}">{{ $job->title }}</a></h2><p>{{ $job->location?->name ?? __('workspace.location_unset') }} · {{ $job->category?->name ?? __('workspace.category_unset') }}</p><p>{{ $job->publication_state === 'CLOSED' || !$job->deadline_at || $job->deadline_at->isPast() ? __('workspace.applications_closed') : __('workspace.accepting') }}</p><p>{{ $history ? __('workspace.last_viewed') : __('workspace.saved') }}: {{ \Illuminate\Support\Carbon::parse($job->library_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</p>
@if(!$history)<form method="post" action="{{ route('candidate.saved-jobs.destroy', $job->id) }}">@csrf @method('DELETE')<button class="btn btn-secondary">{{ __('workspace.unsave') }}</button></form>@endif</article>
@empty<div class="empty-state"><h2>{{ $history ? __('workspace.no_viewed_jobs') : __('workspace.no_saved_jobs') }}</h2><p>{{ __('workspace.open_job_help') }}</p></div>@endforelse
</div>
{{ $jobs->links() }}
@endsection
