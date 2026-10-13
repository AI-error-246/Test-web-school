<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;
use App\Services\JobWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployerNoteController extends Controller
{
    public function update(Request $request, JobApplication $application, JobWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'employer' && $user->status === 'ACTIVE', 403);
        abort_unless($application->job->company->owner_user_id === $user->id, 404);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:3000'], 'version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($application, $user, $data, $workflow): void {
            $job = $workflow->lockJob($application->job_id);
            abort_unless($job->company->owner_user_id === $user->id, 404);
            abort_unless($job->company->owner->status === 'ACTIVE', 403);
            $locked = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($locked->job_id === $job->id, 409);
            $note = DB::table('employer_application_notes')->where('application_id', $application->id)->first();
            abort_unless((int) ($note->version ?? 0) === (int) $data['version'], 409, __('messages.note_changed'));
            $values = ['employer_id' => $user->id, 'body' => trim($data['body'] ?? ''), 'version' => (int) ($note->version ?? 0) + 1, 'updated_at' => now()];
            if ($note) {
                DB::table('employer_application_notes')->where('application_id', $application->id)->update($values);
            } else {
                DB::table('employer_application_notes')->insert(['application_id' => $application->id, 'created_at' => now(), ...$values]);
            }
        });

        return to_route('applications.show', $application)->with('success', __('messages.note_saved'));
    }
}
