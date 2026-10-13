@extends('layouts.app')
@section('title',__('workspace.applications'))
@section('content')
<div class="page-header"><h1>{{ __('workspace.track_applications') }}</h1><p>{{ __('workspace.track_help') }}</p></div>@include('partials.application-list'){{ $applications->links() }}
@endsection
