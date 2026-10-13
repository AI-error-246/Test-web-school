@extends('layouts.app')
@section('title', __('profile.view'))
@section('content')
<div class="profile-page">
<div class="page-header"><p class="eyebrow">{{ __('profile.overview') }}</p><h1>{{ __('profile.view') }}</h1><p>{{ __('profile.private') }}</p></div>
<section class="panel profile-overview">
    @if($profile->avatar_key)<img class="profile-avatar" src="{{ route('candidate.profile.avatar') }}" alt="{{ __('profile.avatar_alt') }}" width="112" height="112">@else<span class="profile-avatar-placeholder" aria-hidden="true">{{ mb_substr($profile->full_name, 0, 1) }}</span>@endif
    <div class="profile-heading"><h2>{{ $profile->full_name }}</h2><p>{{ $profile->major ?: __('profile.empty') }}</p><p class="muted">{{ $profile->university ?: __('profile.empty') }}</p></div>
    <a class="btn btn-primary" href="{{ route('candidate.profile') }}">{{ __('profile.edit') }}</a>
</section>
@include('candidate.completeness')
<div class="profile-details">
    <section class="panel"><h2>{{ __('profile.contact') }}</h2><dl class="profile-facts">
        <div><dt>{{ __('workspace.full_name') }}</dt><dd>{{ $profile->full_name }}</dd></div>
        <div><dt>{{ __('profile.email') }}</dt><dd>{{ auth()->user()->email }}</dd></div>
        <div><dt>{{ __('workspace.phone') }}</dt><dd>{{ $profile->phone ?: __('profile.empty') }}</dd></div>
    </dl></section>
    <section class="panel"><h2>{{ __('profile.education') }}</h2><dl class="profile-facts">
        <div><dt>{{ __('workspace.university') }}</dt><dd>{{ $profile->university ?: __('profile.empty') }}</dd></div>
        <div><dt>{{ __('workspace.major') }}</dt><dd>{{ $profile->major ?: __('profile.empty') }}</dd></div>
        <div><dt>{{ __('workspace.study_year') }}</dt><dd>{{ $profile->study_year ?: __('profile.empty') }}</dd></div>
    </dl></section>
</div>
<section class="panel"><h2>{{ __('workspace.introduction') }}</h2><p class="profile-content">{{ $profile->introduction ?: __('profile.empty') }}</p></section>
<section class="panel"><h2>{{ __('workspace.skills') }}</h2><p class="profile-content">{{ $profile->skills_text ?: __('profile.empty') }}</p></section>
</div>
@endsection
