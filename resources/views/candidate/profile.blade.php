@extends('layouts.app')
@section('title',__('workspace.personal_profile'))
@section('content')
@include('candidate.completeness')
<div class="page-header"><p class="eyebrow">{{ __('workspace.introduce_yourself') }}</p><h1>{{ __('profile.edit') }}</h1><p>{{ __('workspace.profile_requirements') }}</p><a class="btn btn-secondary" href="{{ route('candidate.profile.show') }}">{{ __('profile.view') }}</a></div>
<form class="panel" action="{{ route('candidate.profile.update') }}" method="post" enctype="multipart/form-data">@csrf @method('PUT')
<div class="profile-avatar-editor">
    @if($profile->avatar_key)<img class="profile-avatar" src="{{ route('candidate.profile.avatar') }}" alt="{{ __('profile.avatar_alt') }}" width="112" height="112">@else<span class="profile-avatar-placeholder" aria-hidden="true">{{ mb_substr($profile->full_name, 0, 1) }}</span>@endif
    <div class="profile-avatar-controls">
        <label class="field">{{ __('profile.avatar') }}<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" aria-describedby="avatar-help"></label>
        <p id="avatar-help" class="muted">{{ __('profile.avatar_help') }}</p>
        @if($profile->avatar_key)<label class="profile-remove"><input type="checkbox" name="remove_avatar" value="1" @checked(old('remove_avatar'))> {{ __('profile.remove_avatar') }}</label><p class="muted">{{ __('profile.remove_help') }}</p>@endif
    </div>
</div>
<div class="profile-readonly"><strong>{{ __('profile.email') }}</strong><p>{{ auth()->user()->email }}</p><small>{{ __('profile.email_help') }}</small></div>
<div class="field-row"><label class="field">{{ __('workspace.full_name') }}<input name="full_name" value="{{ old('full_name',$profile->full_name) }}" required maxlength="120" autocomplete="name"></label><label class="field">{{ __('workspace.phone') }}<input name="phone" value="{{ old('phone',$profile->phone) }}" maxlength="30" autocomplete="tel"></label></div>
<div class="field-row"><label class="field">{{ __('workspace.university') }}<input name="university" value="{{ old('university',$profile->university) }}" maxlength="160"></label><label class="field">{{ __('workspace.major') }}<input name="major" value="{{ old('major',$profile->major) }}" maxlength="160"></label></div>
<label class="field">{{ __('workspace.study_year') }}<input name="study_year" type="number" min="1" max="8" value="{{ old('study_year',$profile->study_year) }}"></label>
<label class="field">{{ __('workspace.introduction') }}<textarea name="introduction" rows="5" maxlength="3000">{{ old('introduction',$profile->introduction) }}</textarea></label><label class="field">{{ __('workspace.skills') }}<textarea name="skills_text" rows="3" maxlength="2000">{{ old('skills_text',$profile->skills_text) }}</textarea></label><div class="profile-actions"><button class="btn btn-primary">{{ __('workspace.save_profile') }}</button><a class="btn btn-secondary" href="{{ route('candidate.profile.show') }}">{{ __('profile.view') }}</a></div></form>
@endsection
