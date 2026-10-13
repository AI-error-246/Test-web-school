<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CandidateLibraryController extends Controller
{
    public function saved(Request $request): View
    {
        return $this->listing($request, 'candidate_saved_jobs', 'saved_at', false);
    }

    public function history(Request $request): View
    {
        return $this->listing($request, 'candidate_job_views', 'viewed_at', true);
    }

    private function listing(Request $request, string $table, string $timeColumn, bool $history): View
    {
        $jobs = JobPosting::publiclyVisible()->with(['company', 'category', 'location'])
            ->join($table.' as library', 'library.job_id', '=', 'job_postings.id')
            ->where('library.candidate_id', $request->user()->id)
            ->select('job_postings.*', 'library.'.$timeColumn.' as library_at')
            ->orderByDesc('library.'.$timeColumn)->orderByDesc('job_postings.id')->paginate(12);

        return view('candidate.library', compact('jobs', 'history'));
    }

    public function save(Request $request, int $job): RedirectResponse
    {
        JobPosting::publiclyVisible()->findOrFail($job);
        DB::table('candidate_saved_jobs')->insertOrIgnore(['candidate_id' => $request->user()->id, 'job_id' => $job, 'saved_at' => now()]);

        return back()->with('success', __('messages.job_saved'));
    }

    public function remove(Request $request, int $job): RedirectResponse
    {
        DB::table('candidate_saved_jobs')->where('candidate_id', $request->user()->id)->where('job_id', $job)->delete();

        return back()->with('success', __('messages.job_unsaved'));
    }

    public function recordView(Request $request, int $job): Response
    {
        JobPosting::publiclyVisible()->findOrFail($job);
        DB::transaction(function () use ($request, $job): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('candidate_job_views')->upsert([
                ['candidate_id' => $request->user()->id, 'job_id' => $job, 'viewed_at' => now()],
            ], ['candidate_id', 'job_id'], ['viewed_at']);
            $keep = DB::table('candidate_job_views')->where('candidate_id', $request->user()->id)
                ->orderByDesc('viewed_at')->orderByDesc('job_id')->limit(50)->pluck('job_id');
            DB::table('candidate_job_views')->where('candidate_id', $request->user()->id)->whereNotIn('job_id', $keep)->delete();
        });

        return response()->noContent();
    }

    public function clearHistory(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('candidate_job_views')->where('candidate_id', $request->user()->id)->delete();
        });

        return back()->with('success', __('messages.history_cleared'));
    }
}
