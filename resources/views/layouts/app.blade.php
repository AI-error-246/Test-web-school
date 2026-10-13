@php
    $workspace = auth()->check() && !request()->routeIs('admin.login*', 'admin.mfa*') && request()->is('candidate*', 'employer*', 'admin*', 'applications*', 'jobs/*/apply', 'jobs/*/applications');
    $roleName = auth()->check() ? __('ui.'.auth()->user()->role) : '';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d6b55">
    <title>@yield('title', __('ui.title')) · InternBridge</title>
    <script src="{{ asset('theme.js') }}?v={{ filemtime(public_path('theme.js')) }}"></script>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ filemtime(public_path('app.css')) }}">
    <link rel="stylesheet" href="{{ asset('toolbar.css') }}?v={{ filemtime(public_path('toolbar.css')) }}">
    <link rel="stylesheet" href="{{ asset('theme.css') }}?v={{ filemtime(public_path('theme.css')) }}">
    @stack('styles')
    <script src="{{ asset('app.js') }}?v={{ filemtime(public_path('app.js')) }}" defer></script>
    <script src="{{ asset('toolbar.js') }}?v={{ filemtime(public_path('toolbar.js')) }}" defer></script>
</head>
<body class="site-shell {{ $workspace ? 'is-workspace' : '' }} {{ request()->routeIs('home') ? 'is-home' : 'has-sparkles' }}">
@stack('background')
@unless(request()->routeIs('home'))<div class="ambient-sparkles" aria-hidden="true"></div>@endunless
<a class="skip-link" href="#main-content">{{ __('ui.skip') }}</a>
<div class="navigation-progress" aria-hidden="true"></div>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ route('home') }}" aria-label="{{ __('ui.home_label') }}">
            <svg class="brand-mark" width="38" height="38" viewBox="0 0 38 38" fill="none" aria-hidden="true"><rect width="38" height="38" rx="12" fill="currentColor"/><path class="brand-bridge" d="M10 27V17a9 9 0 0 1 18 0v10M10 20h18M15 27V17m8 10V17" stroke="#d9f27e" stroke-width="2.5" stroke-linecap="round"/><circle class="brand-traveler" cx="10" cy="20" r="2" fill="white"/></svg>
            <span class="brand-wordmark">Intern<span>Bridge</span></span>
        </a>
        <div class="header-tools">
        <button type="button" class="theme-toggle" data-theme-toggle data-label-light="{{ __('appearance.switch_light') }}" data-label-dark="{{ __('appearance.switch_dark') }}" aria-label="{{ __('appearance.toggle_label') }}" aria-pressed="false" hidden>
            <svg data-theme-icon="sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4 19 5"/></svg>
            <svg data-theme-icon="moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M20.5 14.4A8.7 8.7 0 0 1 9.6 3.5a8.7 8.7 0 1 0 10.9 10.9Z"/></svg>
        </button>
        <form class="language-switcher" action="{{ route('locale.update') }}" method="post">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
            <label class="sr-only" for="site-language">{{ __('ui.language') }}</label>
            <select id="site-language" name="locale">
                @foreach(config('app.supported_locales') as $locale => $name)
                <option value="{{ $locale }}" lang="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ $name }}</option>
                @endforeach
            </select>
            <span id="language-hint" class="sr-only" data-language-hint hidden>{{ __('ui.language_hint') }}</span>
            <button type="submit" aria-label="{{ __('ui.language') }}: {{ __('ui.change_language') }}">{{ __('ui.change_language') }}</button>
        </form>
        @auth
            @if(auth()->user()->role === 'candidate')
                @include('partials.notification-bell')
            @endif
            <details class="toolbar-dropdown account-dropdown">
                <summary class="toolbar-button account-trigger" aria-label="{{ __('toolbar.account') }}" title="{{ __('toolbar.account') }}" aria-controls="account-panel"><span class="avatar-initials" aria-hidden="true">{{ mb_substr(auth()->user()->name,0,1) }}</span></summary>
                <div class="toolbar-panel account-panel" id="account-panel">
                    <div class="account-summary"><strong>{{ auth()->user()->name }}</strong><small>{{ $roleName }}</small></div>
                    <a href="{{ route('dashboard') }}"><x-icon name="grid"/>{{ __('ui.workspace') }}</a>
                    @if(auth()->user()->role === 'candidate')
                        <a href="{{ route('candidate.profile.show') }}"><x-icon name="user"/>{{ __('toolbar.view_profile') }}</a>
                        <a href="{{ route('candidate.profile') }}"><x-icon name="file"/>{{ __('toolbar.edit_profile') }}</a>
                    @elseif(auth()->user()->role === 'employer')
                        <a href="{{ route('employer.company') }}"><x-icon name="building"/>{{ __('site.company_profile') }}</a>
                    @endif
                    <form action="{{ route('logout') }}" method="post">@csrf<button type="submit">{{ __('ui.logout') }} <x-icon name="arrow"/></button></form>
                </div>
            </details>
        @endauth
        <button type="button" class="nav-toggle" aria-controls="primary-navigation" aria-expanded="false" aria-label="{{ __('ui.open_menu') }}"><x-icon name="menu"/><span>{{ __('ui.menu') }}</span></button>
        </div>
        <nav class="nav-links" id="primary-navigation" aria-label="{{ __('ui.navigation') }}">
            <a href="{{ route('jobs.index') }}" @if(request()->routeIs('jobs.index','jobs.show','companies.show')) aria-current="page" @endif>{{ __('ui.jobs') }}</a>
            <a href="{{ route('home') }}#how-it-works">{{ __('ui.how') }}</a>
            @auth
                <a href="{{ route('dashboard') }}" @if($workspace) aria-current="page" @endif>{{ __('ui.workspace') }}</a>
            @else
                <span class="nav-divider" aria-hidden="true"></span>
                <a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>{{ __('ui.login') }}</a>
                <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.start') }} <span aria-hidden="true">↗</span></a>
            @endauth
        </nav>
    </div>
</header>
@if($workspace)
<div class="container workspace-layout">
    <aside class="workspace-sidebar">
        <p class="workspace-label">{{ __('ui.workspace_label') }}</p>
        @include('partials.workspace-nav')
        <div class="workspace-user"><span class="avatar-initials" aria-hidden="true">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ $roleName }}</small></div></div>
    </aside>
@endif
<main id="main-content" class="{{ $workspace ? 'workspace-main' : 'container page-content' }}" tabindex="-1">
    @if(session('demo_auth'))<div class="alert" role="status">{{ __('ui.demo') }}</div>@endif
    @if(session('success'))<div class="alert alert-success" role="status"><x-icon name="check"/>{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert" tabindex="-1"><strong>{{ __('ui.check') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
@if($workspace)</div>@endif
<footer class="site-footer"><div class="container footer-inner"><div><a class="brand" href="{{ route('home') }}">Intern<span>Bridge</span></a><p>{{ __('ui.footer_one') }}<br>{{ __('ui.footer_two') }}</p></div><div><strong>{{ __('ui.journey') }}</strong><a href="{{ route('jobs.index') }}">{{ __('ui.explore') }}</a><a href="{{ route('register') }}">{{ __('ui.register') }}</a></div><div><strong>InternBridge</strong><span>{{ __('ui.platform') }}</span><a href="{{ Route::has('admin.login') ? route('admin.login') : route('login') }}">{{ __('ui.admin_login') }} <span aria-hidden="true">↗</span></a><small>{{ __('ui.project') }}</small></div></div></footer>
@stack('scripts')
@unless(request()->routeIs('login*', 'register*', 'password.*', 'admin.login*', 'admin.mfa*'))
    @include('partials.support-bot')
@endunless
</body>
</html>
