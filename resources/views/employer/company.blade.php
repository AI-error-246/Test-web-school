@extends('layouts.app')
@section('title', __('workspace.company_profile'))
@section('content')
<div class="container workspace">

    <div class="workspace-main">
        <div class="page-header"><div><span class="eyebrow">{{ __('workspace.company_introduction') }}</span><h1>{{ __('workspace.company_profile') }}</h1><p>{{ __('workspace.company_profile_help') }}</p></div></div>
        @if($company?->visibility === 'HIDDEN')<div class="alert alert-warning">{{ __('workspace.company_hidden_edit_help') }}</div>@endif
        <form class="panel" method="post" action="{{ route('employer.company.update') }}" enctype="multipart/form-data">
            @csrf @method('PUT')
            <div class="field"><label for="name">{{ __('workspace.company_name_required') }}</label><input id="name" name="name" required maxlength="200" value="{{ old('name', $company?->name) }}" autocomplete="organization"></div>
            <div class="field"><label for="description">{{ __('workspace.company_intro_required') }}</label><textarea id="description" name="description" required maxlength="10000">{{ old('description', $company?->description) }}</textarea><small class="field-help">{{ __('workspace.company_description_help') }}</small></div>
            <div class="field-row">
                <div class="field"><label for="website">{{ __('workspace.website') }}</label><input id="website" name="website" type="url" maxlength="255" value="{{ old('website', $company?->website) }}" placeholder="{{ __('workspace.website_example') }}"></div>
                <div class="field"><label for="contact_email">{{ __('workspace.contact_email_required') }}</label><input id="contact_email" name="contact_email" type="email" required maxlength="254" value="{{ old('contact_email', $company?->contact_email) }}" autocomplete="email"></div>
            </div>
            <div class="field"><label for="address">{{ __('workspace.address_required') }}</label><input id="address" name="address" required maxlength="255" value="{{ old('address', $company?->address) }}" autocomplete="street-address" aria-describedby="address-map-help"><small class="field-help" id="address-map-help">{{ __('maps.address_help') }}</small></div>
            <div class="field"><label for="logo">{{ __('workspace.company_logo_label') }}</label>@if($company?->logo_key)<img class="company-mark large mb-2" src="{{ route('companies.logo', $company) }}" alt="{{ __('workspace.company_logo', ['company' => $company->name]) }}">@endif<input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-help"><small class="field-help" id="logo-help">{{ __('workspace.logo_help') }}</small></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('workspace.save_company') }}</button>@if($company && $company->visibility === 'VISIBLE')<a class="btn btn-secondary" href="{{ route('companies.show', $company) }}">{{ __('workspace.view_company') }}</a>@endif</div>
        </form>
        <x-company-map :company="$company" :preview="true" />
    </div>
</div>
@endsection
