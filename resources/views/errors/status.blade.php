@extends('layouts.app')
@section('title', __('ui.error_title'))
@section('content')
<div class="empty-state panel"><p class="eyebrow">{{ __('ui.error_code', ['status' => $status]) }}</p><h1>{{ __('ui.error_'.(in_array($status, [403,404,409,419,429]) ? $status : 503)) }}</h1><p>{{ __($status === 409 ? 'ui.conflict_help' : 'ui.error_help') }}</p><div class="actions"><a href="{{ route('home') }}" class="btn btn-primary">{{ __('ui.home') }}</a><a href="{{ route('jobs.index') }}" class="btn btn-secondary">{{ __('ui.jobs') }}</a></div></div>
@endsection
