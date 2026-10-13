@extends('layouts.app')
@section('title',__('site.two_step'))
@section('content')
<div class="auth-shell">
    <aside class="auth-aside">
        <p class="eyebrow">{{ __('site.admin_brand') }}</p>
        <h2>{{ __('site.extra_protection') }}</h2>
        <p>{{ __('site.mfa_intro') }}</p>
        <p class="auth-note">{{ __('site.mfa_private') }}</p>
    </aside>
    <section class="auth-panel panel" aria-labelledby="mfa-title">
        <p class="eyebrow">{{ __('site.step_two') }}</p>
        <h1 id="mfa-title">{{ __('site.two_step') }}</h1>
        @if($enrollment)
            <p>{{ __('site.scan_mfa') }}</p>
            <img src="data:image/svg+xml;base64,{{ base64_encode($enrollment['qr_code']) }}" width="200" height="200" alt="{{ __('site.mfa_qr') }}" style="max-width:100%;background:white">
            <details><summary>{{ __('site.manual_key') }}</summary><p style="overflow-wrap:anywhere"><code>{{ $enrollment['secret'] }}</code></p></details>
            <p class="field-help">{{ __('site.save_mfa_account') }}</p>
        @elseif(!$hasFactor)
            <p>{{ __('site.mfa_not_setup') }}</p>
            <form method="post" action="{{ route('admin.mfa.enroll') }}">@csrf<button class="btn btn-primary" type="submit">{{ __('site.setup_mfa') }}</button></form>
        @else
            <p>{{ __('site.current_code_help') }}</p>
        @endif
        @if($hasFactor)
            <form method="post" action="{{ route('admin.mfa.verify') }}">
                @csrf
                <label class="field">{{ __('site.verification_code') }}<input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus aria-describedby="mfa-help"></label>
                <p id="mfa-help" class="field-help">{{ __('site.code_changes') }}</p>
                <button class="btn btn-primary" type="submit">{{ __('site.confirm_continue') }}</button>
            </form>
        @endif
        <p class="field-help">{{ __('site.lost_mfa_admin') }}</p>
        <a href="{{ route('admin.login') }}">{{ __('site.login_again') }}</a>
    </section>
</div>
@endsection
