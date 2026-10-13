@extends('layouts.app')
@section('title',__('site.find_internships'))
@section('content')
<div class="page-header"><p class="eyebrow">{{ __('site.internships_students') }}</p><h1>{{ __('site.find_fit_title') }}</h1><p>{{ __('site.positions_count', ['count' => $jobs->total()]) }}</p></div>
@if(auth()->user()?->role === 'candidate' && auth()->user()?->status === 'ACTIVE')
<details class="panel"><summary>{{ __('site.save_current_filters') }}</summary>
<form action="{{ route('candidate.saved-searches.store') }}" method="post">@csrf
    @foreach(['q','category','location','mode','sort'] as $key)
        @if(request()->filled($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif
    @endforeach
    <label class="field">{{ __('site.filter_name') }}<input name="name" required maxlength="80" placeholder="{{ __('site.filter_example') }}"></label>
    <p class="field-help">{{ __('site.saved_filter_help') }}</p>
    <div class="form-actions"><button class="btn btn-secondary">{{ __('site.save_filter') }}</button><a href="{{ route('candidate.saved-searches') }}">{{ __('site.my_filters') }}</a></div>
</form></details>
@endif
<div class="filter-layout"><aside class="filter-panel panel"><form action="{{ route('jobs.index') }}" method="get"><label class="field">{{ __('site.keyword') }}<input name="q" value="{{ request('q') }}" maxlength="120" placeholder="{{ __('site.role_company') }}"></label><label class="field">{{ __('site.industry') }}<select name="category"><option value="">{{ __('site.all_industries') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category')==$category->id)>{{ $category->name }}</option>@endforeach</select></label><label class="field">{{ __('site.location') }}<select name="location"><option value="">{{ __('site.all_locations') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location')==$location->id)>{{ $location->name }}</option>@endforeach</select></label><label class="field">{{ __('site.work_mode') }}<select name="mode"><option value="">{{ __('site.all_modes') }}</option>@foreach(['ONSITE','REMOTE','HYBRID'] as $mode)<option @selected(request('mode')===$mode) value="{{ $mode }}">{{ __('status.'.$mode) }}</option>@endforeach</select></label><label class="field">{{ __('site.sort') }}<select name="sort"><option value="newest" @selected(request('sort')!=='deadline')>{{ __('site.newest') }}</option><option value="deadline" @selected(request('sort')==='deadline')>{{ __('site.closing_soon') }}</option></select></label><div class="form-actions"><button class="btn btn-primary">{{ __('site.apply_filters') }}</button><a href="{{ route('jobs.index') }}">{{ __('site.clear_filters') }}</a></div></form></aside>
<section><div class="grid grid-2">@forelse($jobs as $job)@include('partials.job-card')@empty<div class="empty-state"><h2>{{ __('site.no_results') }}</h2><p>{{ __('site.try_filters') }}</p><a class="btn btn-secondary" href="{{ route('jobs.index') }}">{{ __('site.view_all_opportunities') }}</a></div>@endforelse</div>{{ $jobs->links() }}</section></div>
@endsection
