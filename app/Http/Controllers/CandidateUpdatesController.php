<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PublicCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CandidateUpdatesController extends Controller
{
    public function notifications(Request $request): View
    {
        $data = $request->validate(['unread' => 'nullable|boolean']);
        $query = $this->notices($request);
        $unreadCount = (clone $query)->whereNull('h.candidate_read_at')->count();
        $notifications = $query->leftJoin('application_status_emails as email', 'email.history_id', '=', 'h.id')
            ->when($data['unread'] ?? false, fn (Builder $q) => $q->whereNull('h.candidate_read_at'))
            ->select(['h.id', 'h.application_id', 'h.from_status', 'h.to_status', 'h.changed_at', 'h.candidate_read_at', 'a.job_title_snapshot', 'a.company_name_snapshot', 'email.status as email_status', 'email.mode as email_mode'])
            ->orderByDesc('h.changed_at')->orderByDesc('h.id')->paginate(15)->withQueryString();

        return view('candidate.notifications', compact('notifications', 'unreadCount'));
    }

    public function feed(Request $request): JsonResponse
    {
        $query = $this->notices($request);
        $unreadCount = (clone $query)->whereNull('h.candidate_read_at')->count();
        $items = $query->select(['h.id', 'h.application_id', 'h.to_status', 'h.changed_at', 'h.candidate_read_at', 'a.job_title_snapshot', 'a.company_name_snapshot'])
            ->orderByDesc('h.changed_at')->orderByDesc('h.id')->limit(8)->get()
            ->map(static function (object $notice): array {
                $selected = $notice->to_status === 'SELECTED';

                return [
                    'id' => (int) $notice->id,
                    'title' => __($selected ? 'notifications.selected_title' : 'notifications.updated_title'),
                    'body' => __($selected ? 'notifications.selected_body' : 'notifications.updated_body', [
                        'job' => $notice->job_title_snapshot,
                        'company' => $notice->company_name_snapshot,
                        'status' => __('status.'.$notice->to_status),
                    ]),
                    'status' => $notice->to_status,
                    'unread' => $notice->candidate_read_at === null,
                    'changed_at' => Carbon::parse($notice->changed_at)->utc()->toIso8601String(),
                    'url' => route('applications.show', $notice->application_id, false),
                    'read_url' => route('candidate.notifications.read', $notice->id, false),
                ];
            });

        return response()->json(['unread_count' => $unreadCount, 'items' => $items])
            ->header('Cache-Control', 'private, no-store');
    }

    public function markRead(Request $request, int $notification): RedirectResponse|JsonResponse
    {
        $notice = $this->notices($request)->where('h.id', $notification)->select('h.id')->first();
        abort_unless($notice, 404);
        DB::table('application_status_histories')->where('id', $notice->id)->whereNull('candidate_read_at')->update(['candidate_read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['unread_count' => $this->notices($request)->whereNull('h.candidate_read_at')->count()])
                ->header('Cache-Control', 'private, no-store');
        }

        return to_route('candidate.notifications')->with('success', __('messages.notifications_read'));
    }

    public function previewEmail(Request $request, int $notification): Response
    {
        $email = $this->notices($request)->join('application_status_emails as email', 'email.history_id', '=', 'h.id')
            ->where('h.id', $notification)->where('email.mode', 'preview')->where('email.status', 'PREVIEWED')
            ->select('email.id', 'email.preview_path')->first();
        abort_unless($email && $email->preview_path === 'status-email-previews/'.$email->id.'.html', 404);
        abort_unless(Storage::disk('local')->exists($email->preview_path), 404);

        return response(Storage::disk('local')->get($email->preview_path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }

    public function savedSearches(Request $request, PublicCatalog $catalog): View
    {
        $searches = DB::table('candidate_saved_searches')->where('candidate_id', $request->user()->id)->orderByDesc('id')->get();
        foreach ($searches as $search) {
            $search->filters = json_decode($search->filters, true, 512, JSON_THROW_ON_ERROR);
        }

        return view('candidate.saved-searches', ['searches' => $searches, 'categories' => $catalog->categories(), 'locations' => $catalog->locations()]);
    }

    public function storeSearch(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'q' => 'nullable|string|max:120', 'category' => 'nullable|integer|exists:job_categories,id', 'location' => 'nullable|integer|exists:locations,id', 'mode' => 'nullable|in:ONSITE,REMOTE,HYBRID', 'sort' => 'nullable|in:newest,deadline']);
        $filters = array_filter(array_intersect_key($data, array_flip(['q', 'category', 'location', 'mode', 'sort'])), static fn ($value) => $value !== null && $value !== '');
        DB::transaction(function () use ($request, $data, $filters): void {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (DB::table('candidate_saved_searches')->where('candidate_id', $request->user()->id)->count() >= 10) {
                throw ValidationException::withMessages(['name' => __('messages.saved_search_limit')]);
            }
            DB::table('candidate_saved_searches')->insert(['candidate_id' => $request->user()->id, 'name' => $data['name'], 'filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        });

        return to_route('candidate.saved-searches')->with('success', __('messages.search_saved'));
    }

    public function destroySearch(Request $request, int $search): RedirectResponse
    {
        $deleted = DB::table('candidate_saved_searches')->where('candidate_id', $request->user()->id)->where('id', $search)->delete();
        abort_unless($deleted, 404);

        return to_route('candidate.saved-searches')->with('success', __('messages.search_deleted'));
    }

    private function notices(Request $request): Builder
    {
        return DB::table('application_status_histories as h')->join('applications as a', 'a.id', '=', 'h.application_id')->where('a.candidate_id', $request->user()->id)->whereNotNull('h.from_status');
    }
}
