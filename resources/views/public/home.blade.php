@extends('layouts.app')
@section('title',__('site.home_title'))
@push('background')
<div class="sylva-world" aria-hidden="true">
    <div class="sylva-stage" id="sylva-world" data-sylva-host></div>
    <div class="sylva-world-shade"></div>
</div>
@endpush
@section('content')
<section class="landing-hero sylva-hero" aria-label="{{ __('scene.label') }}">
    <p class="sr-only" role="status" data-sylva-status data-loading="{{ __('scene.loading') }}" data-error="{{ __('scene.error') }}" hidden></p>
    <div class="hero-copy reveal">
        <p class="hero-kicker"><span aria-hidden="true"></span> {{ __('site.home_kicker') }}</p>
        <h1 class="hero-title">{{ __('site.hero_title_start') }}<br><em>{{ __('site.hero_title_future') }}</em></h1>
        <p class="hero-description">{{ __('site.hero_description') }}</p>
        <form class="hero-search" action="{{ route('jobs.index') }}" method="get" role="search">
            <label for="hero-query" class="sr-only">{{ __('site.search_label') }}</label>
            <x-icon name="search"/>
            <input id="hero-query" name="q" placeholder="{{ __('site.search_placeholder') }}" maxlength="120">
            <button class="btn btn-primary" type="submit">{{ __('site.find_opportunities') }} <x-icon name="arrow"/></button>
        </form>
        <div class="hero-actions"><span>{{ __('site.quick_explore') }}</span>@foreach($categories->take(3) as $category)<a href="{{ route('jobs.index',['category'=>$category->id]) }}">{{ $category->name }} <span aria-hidden="true">↗</span></a>@endforeach</div>
        <div class="hero-proof"><div><strong>{{ $jobCount }}<span aria-hidden="true">+</span></strong><span>{{ __('site.open_positions') }}</span></div><div><strong>{{ $companyCount }}</strong><span>{{ __('site.companies') }}</span></div><p><x-icon name="check"/> {{ __('site.clear_opportunities') }}<br>{{ __('site.apply_your_cv') }}</p></div>
    </div>
    <div class="sylva-caption"><span class="sylva-caption-mark" aria-hidden="true">↗</span><div><strong>{{ __('site.next_chapter') }}</strong><span>{{ __('scene.hint') }}</span><noscript><span>{{ __('scene.no_script') }}</span></noscript></div></div>
</section>
@if(app()->environment('local') && config('app.demo_mode') && !auth()->check())
<section class="panel demo-panel"><div><p class="eyebrow">{{ __('site.demo_local') }}</p><h2>{{ __('site.explore_roles') }}</h2><p>{{ __('site.demo_local_note') }}</p></div><div class="actions">@foreach(['candidate'=>__('site.student'),'employer'=>__('site.employer')] as $role=>$label)<form action="{{ route('demo.login',$role) }}" method="post">@csrf<label class="field">{{ __('site.demo_role', ['role' => $label]) }}<select name="user_id">@foreach($demoUsers->where('role',$role) as $demoUser)<option value="{{ $demoUser->id }}">{{ $demoUser->name }}</option>@endforeach</select></label><button class="btn btn-secondary">{{ __('site.try_role', ['role' => $label]) }}</button></form>@endforeach</div></section>
@endif
<section class="opportunity-section" id="opportunities">
    <div class="section-intro reveal"><div><p class="eyebrow">{{ __('site.latest_opportunities') }}</p><h2>{{ __('site.next_start') }}<br>{{ __('site.your_start') }}</h2></div><div><p>{{ __('site.find_what_you_like') }}<br>{{ __('site.skills_to_grow') }}</p><a class="text-link" href="{{ route('jobs.index') }}">{{ __('site.explore_all_positions', ['count' => $jobCount]) }} <x-icon name="arrow"/></a></div></div>
    <div class="grid grid-3">@forelse($jobs as $job)@include('partials.job-card')@empty<div class="empty-state"><h3>{{ __('site.new_opportunities_soon') }}</h3><p>{{ __('site.come_back_jobs') }}</p></div>@endforelse</div>
</section>
<section class="category-section reveal">
    <div class="section-header"><div><p class="eyebrow">{{ __('site.choose_direction') }}</p><h2>{{ __('site.start_interests') }}</h2></div></div>
    <div class="category-grid">@foreach($categories as $category)<a class="category-link" href="{{ route('jobs.index',['category'=>$category->id]) }}"><span class="category-number">{{ str_pad((string)$loop->iteration,2,'0',STR_PAD_LEFT) }}</span><span>{{ $category->name }}</span><x-icon name="arrow"/></a>@endforeach</div>
</section>
<section class="process-section" id="how-it-works">
    <div class="section-intro reveal"><div><p class="eyebrow">{{ __('site.intent_action') }}</p><h2>{{ __('site.three_steps') }}</h2></div><p>{{ __('site.no_need_all') }}<br>{{ __('site.ready_begin') }}</p></div>
    <div class="process-grid">
        <article class="process-step reveal"><span class="step-number">01</span><x-icon name="user"/><h3>{{ __('site.tell_story') }}</h3><p>{{ __('site.complete_profile_help') }}</p></article>
        <article class="process-step reveal"><span class="step-number">02</span><x-icon name="search"/><h3>{{ __('site.find_fit') }}</h3><p>{{ __('site.explore_fit_help') }}</p></article>
        <article class="process-step reveal"><span class="step-number">03</span><x-icon name="briefcase"/><h3>{{ __('site.send_confidence') }}</h3><p>{{ __('site.apply_track_help') }}</p></article>
    </div>
</section>
<section class="final-cta reveal"><div><p class="eyebrow">{{ __('site.next_chapter_starts') }}</p><h2>{{ __('site.ready_first') }}</h2><p>{{ __('site.learning_doing') }}</p></div><a class="btn btn-primary" href="{{ auth()->check() ? route('jobs.index') : route('register') }}">{{ auth()->check() ? __('site.explore_opportunities') : __('site.create_profile') }} <x-icon name="arrow"/></a></section>
@endsection
@push('styles')
<link rel="stylesheet" href="{{ asset('sylva-home.css') }}?v={{ filemtime(public_path('sylva-home.css')) }}">
@endpush
@push('scripts')
@if(is_file(public_path('build/sylva/manifest.json')))
    @vite('resources/js/sylva-entry.ts', 'build/sylva')
@endif
@endpush
