@extends('layouts.app')
@section('title',$company->name)
@section('content')
<div class="page-header">@if($company->logo_key)<img src="{{ route('companies.logo',$company) }}" alt="{{ __('site.company_logo', ['company' => $company->name]) }}" class="company-logo">@endif<p class="eyebrow">{{ __('site.company_profile_eyebrow') }}</p><h1>{{ $company->name }}</h1><p>{{ $company->address }}</p></div>
<section class="panel prose"><h2>{{ __('site.introduction') }}</h2><p class="preserve-lines">{{ $company->description }}</p>@if($company->website && preg_match('/^https?:\/\//i',$company->website))<a href="{{ $company->website }}" target="_blank" rel="noopener noreferrer">{{ __('site.company_website') }}</a>@endif<p>{{ __('site.contact') }} {{ $company->contact_email }}</p></section>
<x-company-map :company="$company" />
<div class="section-header"><h2>{{ __('site.open_opportunities') }}</h2></div><div class="grid grid-3">@forelse($jobs as $job)@include('partials.job-card')@empty<p class="empty-state">{{ __('site.company_no_jobs') }}</p>@endforelse</div>{{ $jobs->links() }}
@endsection
