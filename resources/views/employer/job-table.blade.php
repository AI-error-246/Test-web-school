@php($publicationLabels = ['DRAFT' => __('workspace.draft'), 'OPEN' => __('workspace.open'), 'CLOSED' => __('workspace.closed')])
@php($moderationLabels = ['NOT_SUBMITTED' => __('workspace.not_submitted'), 'PENDING' => __('workspace.pending'), 'APPROVED' => __('workspace.approved'), 'REJECTED' => __('workspace.rejected'), 'HIDDEN' => __('workspace.hidden')])
@if($jobs->isEmpty())
    <div class="empty-state"><h2>{{ __('workspace.no_job_postings') }}</h2><p>{{ __('workspace.first_job_help') }}</p><a class="btn btn-primary" href="{{ route('employer.jobs.create') }}">{{ __('workspace.create_first_job') }}</a></div>
@else
    <div class="table-wrap" tabindex="0" role="region" aria-label="{{ __('workspace.jobs_table_aria') }}"><table class="data-table"><thead><tr><th scope="col">{{ __('workspace.position') }}</th><th scope="col">{{ __('workspace.status') }}</th><th scope="col">{{ __('workspace.application') }}</th><th scope="col">{{ __('workspace.actions') }}</th></tr></thead><tbody>
        @foreach($jobs as $job)
            <tr><td><strong>{{ $job->title }}</strong><small>{{ __('workspace.deadline', ['date' => $job->deadline_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') ?? __('workspace.not_set')]) }}</small>@if($job->deadline_at?->isPast())<span class="badge badge-neutral">{{ __('workspace.deadline_passed') }}</span>@endif</td>
                <td><div class="tag-list"><span class="badge {{ $job->publication_state === 'OPEN' ? 'badge-success' : 'badge-neutral' }}">{{ $publicationLabels[$job->publication_state] }}</span><span class="badge {{ $job->moderation_state === 'APPROVED' ? 'badge-success' : ($job->moderation_state === 'PENDING' ? 'badge-warning' : 'badge-neutral') }}">{{ $moderationLabels[$job->moderation_state] }}</span></div></td>
                <td><a href="{{ route('employer.applications', $job) }}">{{ __('workspace.application_count', ['count' => $job->applications_count]) }}</a></td>
                <td><div class="actions">@if($job->publication_state !== 'CLOSED' && $job->moderation_state !== 'HIDDEN')<a class="btn btn-secondary btn-small" href="{{ route('employer.jobs.edit', $job) }}">{{ __('workspace.edit') }}</a>@endif
                    @if($job->publication_state === 'OPEN')<form method="post" action="{{ route('employer.jobs.close', $job) }}" data-confirm="{{ __('workspace.confirm_close_job') }}">@csrf<input type="hidden" name="version" value="{{ $job->version }}"><button class="btn btn-danger btn-small">{{ __('workspace.close_job') }}</button></form>@endif
                    <a class="btn btn-secondary btn-small" href="{{ route('employer.applications', $job) }}">{{ __('workspace.candidate') }}</a></div></td>
            </tr>
        @endforeach
    </tbody></table></div>
@endif
