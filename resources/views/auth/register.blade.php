@extends('layouts.app')
@section('title',__('site.create_account'))
@section('content')
<section class="auth-shell">
    <aside class="auth-aside reveal"><p class="eyebrow">{{ __('site.start_internbridge') }}</p><h2>{{ __('site.new_connection') }}<br>{{ __('site.new_direction') }}</h2><p>{{ __('site.register_intro') }}</p><div class="auth-note"><x-icon name="check"/><p>{{ __('site.students_grow') }}<br>{{ __('site.companies_talent') }}</p></div></aside>
    <div class="auth-panel reveal"><p class="eyebrow">{{ __('site.hello_friend') }}</p><h1>{{ __('site.create_account') }}</h1><p>{{ __('site.choose_role') }}</p>
        <form method="post" action="{{ route('register.store') }}">@csrf
            <div hidden aria-hidden="true"><label>{{ __('site.leave_empty') }}<input name="contact_website" tabindex="-1" autocomplete="off"></label></div>
            <label class="field">{{ __('site.your_role') }}<select name="role" required><option value="candidate" @selected(old('role')==='candidate')>{{ __('site.student_candidate') }}</option><option value="employer" @selected(old('role')==='employer')>{{ __('site.employer') }}</option></select></label>
            <label class="field">{{ __('site.full_name') }}<input name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" placeholder="{{ __('site.your_name') }}"></label>
            <label class="field">{{ __('site.email') }}<input name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email" placeholder="{{ __('site.email_example') }}"></label>
            <div class="field"><label for="register-password">{{ __('site.password') }}</label><div class="password-field"><input id="register-password" name="password" type="password" required minlength="8" maxlength="256" autocomplete="new-password" aria-describedby="password-help"><button type="button" data-password-toggle data-show-label="{{ __('site.show') }}" data-hide-label="{{ __('site.hide') }}" data-show-aria="{{ __('site.show_password') }}" data-hide-aria="{{ __('site.hide_password') }}" aria-controls="register-password" aria-pressed="false" aria-label="{{ __('site.show_password') }}">{{ __('site.show') }}</button></div><small class="field-help" id="password-help">{{ __('site.password_min') }}</small></div>
            <label class="field">{{ __('site.confirm_password') }}<input name="password_confirmation" type="password" required minlength="8" maxlength="256" autocomplete="new-password"></label>
            <button class="btn btn-primary" type="submit">{{ __('site.create_account') }} <x-icon name="arrow"/></button>
        </form>
        <p class="field-help">{{ __('site.confirm_email_register') }}</p>
        <p>{{ __('site.have_account') }} <a href="{{ route('login') }}">{{ __('site.login') }}</a></p>
    </div>
</section>
@endsection
