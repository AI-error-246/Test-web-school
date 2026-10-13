@extends('layouts.app')
@section('title', __('workspace.account_detail'))
@section('content')
<div class="container"><h1>{{ $account->name }}</h1>
    <div class="grid grid-2"><section class="panel"><h2>{{ __('workspace.account_info') }}</h2><p>{{ $account->email }}</p><p>{{ $account->role === 'candidate' ? __('workspace.candidate') : __('workspace.employer') }} · {{ $account->status === 'ACTIVE' ? __('workspace.active') : __('workspace.blocked') }}</p>
        @if($account->candidateProfile)<p>{{ __('workspace.university_value', ['university' => $account->candidateProfile->university ?: __('workspace.not_updated')]) }}</p><p>{{ __('workspace.major_value', ['major' => $account->candidateProfile->major ?: __('workspace.not_updated')]) }}</p><p>{{ __('workspace.cv_count_admin', ['count' => $cvCount]) }}</p>@endif
    </section><form class="panel" method="POST" action="{{ route('admin.users.status', $account) }}">@csrf @method('PATCH')
        <h2>{{ $account->status === 'ACTIVE' ? __('workspace.block_account') : __('workspace.unblock_account') }}</h2><input type="hidden" name="current_status" value="{{ $account->status }}"><input type="hidden" name="status" value="{{ $account->status === 'ACTIVE' ? 'BLOCKED' : 'ACTIVE' }}">
        <label class="field">{{ __('workspace.reason_required') }}<textarea name="reason" required maxlength="2000">{{ old('reason') }}</textarea></label><button class="btn btn-danger">{{ __('workspace.confirm_status_change') }}</button>
    </form></div>
    @if($account->company)<section class="panel"><h2>{{ $account->company->name }}</h2><p>{{ $account->company->description }}</p><p>{{ $account->company->contact_email }} · {{ $account->company->address }}</p><p>{{ $account->company->visibility === 'VISIBLE' ? __('workspace.visible') : __('workspace.currently_hidden') }}</p>
        <form method="POST" action="{{ route('admin.companies.visibility', $account->company) }}">@csrf @method('PATCH')<input type="hidden" name="current_visibility" value="{{ $account->company->visibility }}"><input type="hidden" name="visibility" value="{{ $account->company->visibility === 'VISIBLE' ? 'HIDDEN' : 'VISIBLE' }}">
            <label class="field">{{ __('workspace.visibility_reason') }}<textarea name="reason" required maxlength="2000"></textarea></label><button class="btn btn-secondary">{{ $account->company->visibility === 'VISIBLE' ? __('workspace.hide_company') : __('workspace.restore_company') }}</button>
        </form>
    </section>@endif
    <section class="panel"><h2>{{ __('workspace.account_history') }}</h2>@forelse($history as $event)<p>{{ $event->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · {{ __('status.'.$event->from_status) }} → {{ __('status.'.$event->to_status) }} · {{ $event->reason }} · {{ __('workspace.performed_by', ['name' => $event->admin->name]) }}</p>@empty<p>{{ __('workspace.no_status_changes') }}</p>@endforelse</section>
    @if($account->company)<section class="panel"><h2>{{ __('workspace.company_visibility_history') }}</h2>@forelse($companyHistory as $event)<p>{{ $event->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · {{ __('status.'.$event->from_state) }} → {{ __('status.'.$event->to_state) }} · {{ $event->reason }} · {{ $event->admin->name }}</p>@empty<p>{{ __('workspace.no_visibility_changes') }}</p>@endforelse</section>@endif
</div>
@endsection
