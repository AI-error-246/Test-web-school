@props(['company', 'preview' => false])
@if(filled($company?->address))
@php
    $mapQuery = trim($company->address);
    $mapLink = 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $mapQuery], '', '&', PHP_QUERY_RFC3986);
    $mapEmbed = 'https://www.google.com/maps?'.http_build_query(['q' => $mapQuery, 'output' => 'embed', 'hl' => app()->getLocale()], '', '&', PHP_QUERY_RFC3986);
@endphp
@once
    @push('styles')
    <link rel="stylesheet" href="{{ asset('company-map.css') }}?v={{ filemtime(public_path('company-map.css')) }}">
    @endpush
    @push('scripts')
    <script src="{{ asset('company-map.js') }}?v={{ filemtime(public_path('company-map.js')) }}" defer></script>
    @endpush
@endonce
<section class="panel company-map" data-company-map data-map-src="{{ $mapEmbed }}" data-map-title="{{ __('maps.frame_title', ['company' => $company->name]) }}" data-map-loading="{{ __('maps.loading') }}" data-map-loaded="{{ __('maps.loaded') }}" data-map-error="{{ __('maps.error') }}">
    <div class="company-map-heading">
        <span class="company-map-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
        <div><h2>{{ $preview ? __('maps.preview_title') : __('maps.title') }}</h2><p>{{ $company->address }}</p></div>
    </div>
    @if($preview)<p class="field-help">{{ __('maps.saved_address') }}</p>@endif
    <div class="company-map-canvas" data-map-canvas>
        <div class="company-map-placeholder" data-map-placeholder>
            <svg class="company-map-art" width="76" height="76" viewBox="0 0 76 76" fill="none" aria-hidden="true"><path d="m8 22 20-8 20 8 20-8v42l-20 8-20-8-20 8V22Z" stroke="currentColor" stroke-width="2"/><path d="M28 14v42m20-34v42" stroke="currentColor" stroke-width="2"/><path d="M49 26c0 8-11 18-11 18S27 34 27 26a11 11 0 1 1 22 0Z" fill="var(--surface, #fff)" stroke="currentColor" stroke-width="2"/><circle cx="38" cy="26" r="3.5" fill="currentColor"/></svg>
            <p>{{ __('maps.placeholder') }}</p>
            <button class="btn btn-primary" type="button" data-map-load hidden>{{ __('maps.load') }}</button>
            <noscript><p>{{ __('maps.no_script') }}</p></noscript>
        </div>
    </div>
    <p class="field-help company-map-status" role="status" aria-live="polite" data-map-status></p>
    <div class="company-map-footer"><p class="field-help">{{ __('maps.privacy') }}</p><a class="btn btn-secondary" href="{{ $mapLink }}" target="_blank" rel="noopener noreferrer" data-map-external>{{ __('maps.open') }} <span aria-hidden="true">↗</span></a></div>
    <p class="field-help company-map-accuracy">{{ __('maps.accuracy') }}</p>
</section>
@endif
