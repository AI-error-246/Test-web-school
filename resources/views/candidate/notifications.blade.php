@extends('layouts.app')
@section('title', __('workspace.application_notifications'))
@section('content')
<div class="page-header"><h1>{{ __('workspace.application_notifications') }}</h1><p>{{ __('workspace.unread_summary', ['count' => $unreadCount]) }}</p></div>
<div class="actions"><a class="btn btn-secondary" href="{{ route('candidate.notifications') }}">{{ __('workspace.all') }}</a><a class="btn btn-secondary" href="{{ route('candidate.notifications', ['unread' => 1]) }}">{{ __('workspace.unread') }}</a></div>
<p class="field-help">{{ __('workspace.email_preview_help') }}</p>
<div class="table-wrap" tabindex="0" role="region" aria-label="{{ __('workspace.application_notifications') }}"><table class="data-table"><thead><tr><th>{{ __('workspace.position_company_heading') }}</th><th>{{ __('workspace.update') }}</th><th>{{ __('workspace.time') }}</th><th>{{ __('workspace.email') }}</th><th>{{ __('workspace.actions') }}</th></tr></thead><tbody>
@forelse($notifications as $notification)
<tr><td><strong>{{ $notification->job_title_snapshot }}</strong><br>{{ $notification->company_name_snapshot }}</td><td>
    @if($notification->to_status === 'SELECTED')<strong class="badge badge-success">{{ __('notifications.selected_title') }}</strong><br>@endif
    {{ __('status.'.$notification->from_status) }} → {{ __('status.'.$notification->to_status) }}<br><span class="badge badge-neutral">{{ $notification->candidate_read_at ? __('workspace.read') : __('workspace.unread') }}</span></td><td>{{ \Illuminate\Support\Carbon::parse($notification->changed_at)->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</td>
<td>
    @if($notification->email_status === 'PREVIEWED' && $notification->email_mode === 'preview')
        <a href="{{ route('candidate.notifications.email', $notification->id) }}" target="_blank" rel="noopener noreferrer">{{ __('workspace.view_email_preview') }}</a>
    @else
        {{ ['PENDING' => __('workspace.email_pending'), 'PROCESSING' => __('workspace.email_processing'), 'SENT' => __('workspace.email_sent'), 'FAILED' => __('workspace.email_failed'), 'SKIPPED' => __('workspace.email_skipped')][$notification->email_status] ?? '—' }}
        @if($notification->email_mode === 'preview')<small> {{ __('workspace.simulation_suffix') }}</small>@endif
    @endif
</td><td><a href="{{ route('applications.show', $notification->application_id) }}">{{ __('workspace.view_application_detail') }}</a>@if(!$notification->candidate_read_at)<form method="post" action="{{ route('candidate.notifications.read', $notification->id) }}">@csrf<button class="btn btn-secondary" type="submit">{{ __('workspace.mark_read') }}</button></form>@endif</td></tr>
@empty<tr><td colspan="5"><div class="empty-state">{{ __('workspace.no_notifications') }}</div></td></tr>@endforelse
</tbody></table></div>{{ $notifications->links() }}
@endsection
