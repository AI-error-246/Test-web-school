@extends('layouts.app')
@section('title',__('site.login'))
@section('content')
<section class="auth-shell">
    <aside class="auth-aside reveal"><p class="eyebrow">{{ __('site.welcome_back') }}</p><h2>{{ __('site.every_start') }}<br>{{ __('site.new_opportunity') }}</h2><p>{{ __('site.continue_exploring') }}</p><div class="auth-note"><x-icon name="briefcase"/><p>{{ __('site.your_profiles_opportunities') }}<br>{{ __('site.one_space') }}</p></div></aside>
    <div class="auth-panel reveal"><p class="eyebrow">{{ __('site.your_space') }}</p><h1>{{ __('site.login') }}</h1><p>{{ __('site.nice_see_again') }}</p>
        <form method="post" action="{{ route('login.store') }}">@csrf
            <div class="field"><label for="login-email">{{ __('site.email') }}</label><input id="login-email" name="email" type="email" required autocomplete="username" value="{{ old('email') }}" maxlength="254" placeholder="{{ __('site.email_example') }}" @error('email') aria-invalid="true" aria-describedby="login-email-error" @enderror>@error('email')<small class="field-error" id="login-email-error">{{ $message }}</small>@enderror</div>
            <div class="field"><label for="login-password">{{ __('site.password') }}</label><div class="password-field"><input id="login-password" name="password" type="password" required autocomplete="current-password" maxlength="256" placeholder="{{ __('site.enter_password') }}"><button type="button" data-password-toggle data-show-label="{{ __('site.show') }}" data-hide-label="{{ __('site.hide') }}" data-show-aria="{{ __('site.show_password') }}" data-hide-aria="{{ __('site.hide_password') }}" aria-controls="login-password" aria-pressed="false" aria-label="{{ __('site.show_password') }}">{{ __('site.show') }}</button></div></div>
            <button class="btn btn-primary" type="submit">{{ __('site.login') }} <x-icon name="arrow"/></button>
        </form>
        <p><a href="{{ route('password.request') }}">{{ __('site.forgot_question') }}</a></p>
        <p>{{ __('site.no_account') }} <a href="{{ route('register') }}">{{ __('site.register_now') }}</a></p>
        <p class="field-help">{{ __('site.confirm_email_login') }}</p>
        <a class="text-link" href="{{ route('admin.login') }}"><x-icon name="shield"/> {{ __('site.admin_signin_link') }}</a>
        @if(app()->environment('local') && config('app.demo_mode'))<hr><p>{{ __('site.try_local_data') }}</p><div class="actions">@foreach(['candidate'=>__('site.student'),'employer'=>__('site.employer')] as $role=>$label)<form method="post" action="{{ route('demo.login',$role) }}">@csrf<button class="btn btn-secondary">{{ $label }}</button></form>@endforeach</div>@endif
    </div>
</section>
@endsection
