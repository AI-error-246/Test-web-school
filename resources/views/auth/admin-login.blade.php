@extends('layouts.app')
@section('title',__('site.admin_login'))
@section('content')
<div class="auth-shell">
    <aside class="auth-aside">
        <p class="eyebrow">{{ __('site.admin_brand') }}</p>
        <h2>{{ __('site.trusted_recruitment') }}</h2>
        <p>{{ __('site.admin_intro') }}</p>
        <p class="auth-note">{{ __('site.admin_only') }}</p>
    </aside>
    <section class="auth-panel panel" aria-labelledby="admin-login-title">
        <p class="eyebrow">{{ __('site.secure_access') }}</p>
        <h1 id="admin-login-title">{{ __('site.admin_login') }}</h1>
        <p>{{ __(config('auth.admin_mfa_required', true) !== false ? 'site.admin_login_help' : 'site.admin_password_login_help') }}</p>
        @if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
        <form method="post" action="{{ route('admin.login.store') }}">
            @csrf
            <label class="field">{{ __('site.admin_email') }}<input name="email" type="email" required autocomplete="username" value="{{ old('email', auth()->user()?->email) }}" maxlength="254"></label>
            <div class="field"><label for="admin-password">{{ __('site.password') }}</label><div class="password-field"><input id="admin-password" name="password" type="password" required autocomplete="current-password" maxlength="256"><button type="button" data-password-toggle data-show-label="{{ __('site.show') }}" data-hide-label="{{ __('site.hide') }}" data-show-aria="{{ __('site.show_password') }}" data-hide-aria="{{ __('site.hide_password') }}" aria-controls="admin-password" aria-pressed="false" aria-label="{{ __('site.show_password') }}">{{ __('site.show') }}</button></div></div>
            <button class="btn btn-primary" type="submit">{{ __('site.admin_login') }}</button>
        </form>
        <p class="field-help">{{ __(config('auth.admin_mfa_required', true) !== false ? 'site.admin_session_help' : 'site.admin_password_session_help') }}</p>
        <p><a href="{{ route('password.request') }}">{{ __('site.forgot_question') }}</a></p>
        <a href="{{ route('home') }}">{{ __('site.back_home') }}</a>
    </section>
</div>
@endsection
