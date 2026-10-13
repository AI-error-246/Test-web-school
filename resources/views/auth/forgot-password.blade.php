@extends('layouts.app')
@section('title',__('site.forgot_password'))
@section('content')
<section class="auth-shell">
    <aside class="auth-aside"><p class="eyebrow">{{ __('site.back_journey') }}</p><h2>{{ __('site.recover_access') }}</h2><p>{{ __('site.recovery_email_help') }}</p></aside>
    <div class="auth-panel"><h1>{{ __('site.forgot_password') }}</h1><p>{{ __('site.registered_email_help') }}</p>
        <form method="post" action="{{ route('password.email') }}">@csrf
            <div hidden aria-hidden="true"><label>{{ __('site.website') }}<input name="contact_website" tabindex="-1" autocomplete="off"></label></div>
            <div class="field"><label for="recovery-email">{{ __('site.account_email') }}</label><input id="recovery-email" type="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="recovery-email-error" @enderror>@error('email')<small id="recovery-email-error" class="field-error">{{ $message }}</small>@enderror</div>
            <button type="submit" class="btn btn-primary">{{ __('site.send_recovery') }}</button>
        </form>
        <p class="field-help">{{ __('site.recovery_spam_help') }}</p>
        <a href="{{ route('login') }}">{{ __('site.back_login') }}</a>
    </div>
</section>
@endsection
