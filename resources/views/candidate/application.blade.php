@extends('layouts.app')
@section('title',__('workspace.application_detail'))
@section('content')
<div class="page-header"><p class="eyebrow">{{ __('workspace.application_number', ['id' => $application->id]) }}</p><h1>{{ $application->job_title_snapshot }}</h1><p>{{ $application->company_name_snapshot }}</p><span class="badge badge-neutral">{{ __('status.'.$application->status) }}</span></div>
@include('partials.application-detail')
@if(auth()->user()->role === 'employer')
    @include('employer.note-form')
@endif
@endsection
