<article class="job-card reveal">
    <div class="job-card-heading"><span class="company-monogram" aria-hidden="true">{{ mb_substr($job->company->name,0,2) }}</span><span class="job-type">{{ __('status.'.$job->work_mode) }}</span></div>
    <p class="eyebrow">{{ $job->category?->name ?? __('site.internship') }}</p>
    <h3><a href="{{ route('jobs.show',$job) }}">{{ $job->title }}</a></h3>
    <a class="job-company" href="{{ route('companies.show',$job->company) }}">{{ $job->company->name }}</a>
    <div class="job-card-tags"><span><x-icon name="pin"/>{{ $job->location?->name }}</span><span>{{ __('site.student_intern') }}</span></div>
    <p class="salary">@if($job->allowance_min!==null){{ number_format((float)$job->allowance_min,0,',','.') }}@if($job->allowance_max!==null) – {{ number_format((float)$job->allowance_max,0,',','.') }}@endif <small>{{ __('site.vnd_month') }}</small>@else {{ __('site.allowance_not_disclosed') }} @endif</p>
    <div class="job-card-footer"><span class="deadline"><x-icon name="clock"/>{{ $job->deadline_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') }}</span><a class="btn btn-secondary" href="{{ route('jobs.show',$job) }}">{{ __('site.details') }} <x-icon name="arrow"/></a></div>
</article>