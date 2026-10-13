@extends('layouts.app')
@section('title', __('workspace.manage_accounts'))
@section('content')
<div class="container"><h1>{{ __('workspace.manage_accounts') }}</h1>
    <form class="panel grid grid-3" method="GET" action="{{ route('admin.users') }}">
        <label class="field">{{ __('workspace.name_email') }}<input name="q" value="{{ request('q') }}" maxlength="100"></label>
        <label class="field">{{ __('workspace.role') }}<select name="role"><option value="">{{ __('workspace.all') }}</option><option value="candidate" @selected(request('role') === 'candidate')>{{ __('workspace.candidate') }}</option><option value="employer" @selected(request('role') === 'employer')>{{ __('workspace.employer') }}</option></select></label>
        <label class="field">{{ __('workspace.status') }}<select name="status"><option value="">{{ __('workspace.all') }}</option><option value="ACTIVE" @selected(request('status') === 'ACTIVE')>{{ __('workspace.active') }}</option><option value="BLOCKED" @selected(request('status') === 'BLOCKED')>{{ __('workspace.blocked') }}</option></select></label>
        <button class="btn btn-primary">{{ __('workspace.filter_accounts') }}</button>
    </form>
    <div class="panel table-wrap"><table class="data-table"><thead><tr><th>{{ __('workspace.full_name') }}</th><th>{{ __('workspace.email') }}</th><th>{{ __('workspace.role') }}</th><th>{{ __('workspace.status') }}</th><th>{{ __('workspace.details') }}</th></tr></thead><tbody>
        @forelse($users as $account)<tr><td>{{ $account->name }}</td><td>{{ $account->email }}</td><td>{{ $account->role === 'candidate' ? __('workspace.candidate') : __('workspace.employer') }}</td><td>{{ $account->status === 'ACTIVE' ? __('workspace.active') : __('workspace.blocked') }}</td><td><a href="{{ route('admin.users.show', $account) }}">{{ __('workspace.view_account') }}</a></td></tr>
        @empty<tr><td colspan="5">{{ __('workspace.no_accounts') }}</td></tr>@endforelse
    </tbody></table></div>{{ $users->links() }}
</div>
@endsection
