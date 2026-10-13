<?php

namespace App\Http\Controllers;

use App\Models\CvDocument;
use App\Models\User;
use App\Services\CvSafety;
use App\Services\CvStorage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CvController extends Controller
{
    public function index(Request $request): View
    {
        return view('candidate.cvs', ['cvs' => CvDocument::where('candidate_id', $request->user()->id)->orderByDesc('uploaded_at')->get()]);
    }

    public function store(Request $request, CvStorage $storage, CvSafety $safety): RedirectResponse
    {
        $request->validate(['display_name' => 'required|string|max:120', 'cv' => 'required|file|mimes:pdf|mimetypes:application/pdf|max:5120']);
        $file = $request->file('cv');
        if (! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
            throw ValidationException::withMessages(['cv' => __('messages.cv_pdf_required')]);
        }
        if (CvDocument::where('candidate_id', $request->user()->id)->whereNull('archived_at')->count() >= 5) {
            throw ValidationException::withMessages(['cv' => __('messages.cv_limit')]);
        }
        $scan = $safety->scanBytes((string) file_get_contents($file->getRealPath()));
        if ($scan['scan_status'] === 'INFECTED') {
            throw ValidationException::withMessages(['cv' => __('messages.cv_unsafe')]);
        }
        abort_unless($scan['scan_status'] === 'CLEAN', 503, __('messages.cv_scan_unconfirmed'));
        $stored = null;
        try {
            DB::transaction(function () use ($request, $file, $storage, $scan, &$stored): void {
                $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($owner->status === 'ACTIVE' && $owner->role === 'candidate', 403);
                if (CvDocument::where('candidate_id', $request->user()->id)->whereNull('archived_at')->count() >= 5) {
                    throw ValidationException::withMessages(['cv' => __('messages.cv_limit')]);
                }
                $stored = $storage->store($file);
                CvDocument::create(['candidate_id' => $request->user()->id, 'display_name' => $request->input('display_name'), 'disk' => $stored['disk'], 'storage_key' => $stored['key'], 'original_filename' => $file->getClientOriginalName(), 'mime_type' => 'application/pdf', 'size_bytes' => $file->getSize(), 'uploaded_at' => now(), ...$scan]);
            });
        } catch (\Throwable $e) {
            if ($stored) {
                try {
                    $storage->delete($stored['disk'], $stored['key']);
                } catch (\Throwable) {
                    Log::warning('CV compensation failed; review orphan storage object.', ['disk' => $stored['disk'], 'key' => $stored['key']]);
                }
            }
            throw $e;
        }

        return back()->with('success', __('messages.cv_uploaded'));
    }

    public function update(Request $request, int $cv): RedirectResponse
    {
        $record = CvDocument::where('candidate_id', $request->user()->id)->findOrFail($cv);
        $data = $request->validate(['display_name' => 'required|string|max:120']);
        $record->update($data);

        return back()->with('success', __('messages.cv_renamed'));
    }

    public function archive(Request $request, int $cv): RedirectResponse
    {
        DB::transaction(function () use ($request, $cv): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            CvDocument::where('candidate_id', $request->user()->id)->findOrFail($cv)->update(['archived_at' => now()]);
        });

        return back()->with('success', __('messages.cv_archived'));
    }

    public function show(Request $request, int $cv, CvStorage $storage): Response
    {
        $record = CvDocument::findOrFail($cv);
        $user = $request->user();
        $allowed = $user->role === 'candidate' && $record->candidate_id === $user->id;
        if ($user->role === 'employer') {
            $allowed = $record->applications()->whereHas('job.company', fn ($q) => $q->where('owner_user_id', $user->id))->exists();
        }
        abort_unless($allowed, 404);

        return $storage->response($record);
    }
}
