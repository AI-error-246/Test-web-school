@extends('layouts.app')
@section('title',__('workspace.candidate_workspace'))
@section('content')
@include('candidate.completeness')
<div class="page-header"><p class="eyebrow">{{ __('workspace.candidate_workspace') }}</p><h1>{{ __('workspace.hello', ['name' => auth()->user()->name]) }}</h1><p>{{ __('workspace.opportunity_step') }}</p></div><div class="stats"><div class="stat-card"><span>{{ __('workspace.applied') }}</span><strong>{{ $total }}</strong></div><div class="stat-card"><span>{{ __('workspace.reviewing') }}</span><strong>{{ $reviewing }}</strong></div><div class="stat-card"><span>{{ __('workspace.selected') }}</span><strong>{{ $selected }}</strong></div></div>
@if(!$profile->phone || !$profile->university || !$profile->major)<div class="panel"><h2>{{ __('workspace.complete_before_apply') }}</h2><p>{{ __('workspace.profile_required_help') }}</p><a href="{{ route('candidate.profile') }}" class="btn btn-primary">{{ __('workspace.update_profile') }}</a></div>@endif
<div class="section-header"><h2>{{ __('workspace.recent_applications') }}</h2><a href="{{ route('jobs.index') }}" class="btn btn-secondary">{{ __('workspace.explore') }}</a></div>@include('partials.application-list')<p><a href="{{ route('candidate.applications') }}">{{ __('workspace.all_applications_arrow') }}</a></p>
@endsection
