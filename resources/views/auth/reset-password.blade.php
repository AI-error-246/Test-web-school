@extends('layouts.app')
@section('title',__('site.reset_password'))
@section('content')
<section class="auth-shell">
    <aside class="auth-aside"><p class="eyebrow">{{ __('site.new_beginning') }}</p><h2>{{ __('site.set_new_password') }}</h2><p>{{ __('site.after_reset') }}</p></aside>
    <div class="auth-panel"><h1>{{ __('site.reset_password') }}</h1><p>{{ __('site.reset_ten_minutes') }}</p>
        @if($requiresMfa)
        <p>{{ __('site.recovery_mfa_enabled') }}</p>
        <form method="post" action="{{ route('password.mfa') }}">@csrf
            <div class="field"><label for="recovery-code">{{ __('site.verification_code') }}</label><input id="recovery-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required aria-describedby="recovery-mfa-help @error('code') recovery-code-error @enderror"><small id="recovery-mfa-help">{{ __('site.recovery_code_help') }}</small>@error('code')<small class="field-error" id="recovery-code-error">{{ $message }}</small>@enderror</div>
            <button type="submit" class="btn btn-primary">{{ __('site.verify_change_password') }}</button>
        </form>
        <p class="field-help">{{ __('site.lost_recovery_device') }}</p>
        @else
        <form method="post" action="{{ route('password.update') }}">@csrf
            <div class="field"><label for="new-password">{{ __('site.new_password') }}</label><input id="new-password" name="password" type="password" required minlength="8" maxlength="256" autocomplete="new-password" aria-describedby="password-help @error('password') password-error @enderror"><small id="password-help">{{ __('site.strong_password_help') }}</small>@error('password')<small class="field-error" id="password-error">{{ $message }}</small>@enderror</div>
            <div class="field"><label for="confirm-password">{{ __('site.confirm_new_password') }}</label><input id="confirm-password" name="password_confirmation" type="password" required minlength="8" maxlength="256" autocomplete="new-password"></div>
            <button type="submit" class="btn btn-primary">{{ __('site.save_new_password') }}</button>
        </form>
        @endif
        <p><a href="{{ route('password.request') }}">{{ __('site.request_new_link') }}</a></p>
    </div>
</section>
@endsection
