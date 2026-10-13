<?php

namespace App\Http\Controllers;

use App\Models\CandidateProfile;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\ProfileAvatarStorage;
use App\Services\ProfileCompleteness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidateController extends Controller
{
    public function dashboard(Request $request): View
    {
        $profile = CandidateProfile::firstOrCreate(['user_id' => $request->user()->id], ['full_name' => $request->user()->name]);
        $query = JobApplication::where('candidate_id', $request->user()->id);
        $counts = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('candidate.dashboard', ['profile' => $profile, 'completeness' => app(ProfileCompleteness::class)->forProfile($profile), 'total' => $counts->sum(), 'reviewing' => (int) $counts->get('REVIEWING', 0), 'selected' => (int) $counts->get('SELECTED', 0), 'applications' => $query->latest('applied_at')->limit(5)->get()]);
    }

    public function profile(Request $request): View
    {
        $profile = CandidateProfile::firstOrCreate(['user_id' => $request->user()->id], ['full_name' => $request->user()->name]);

        return view('candidate.profile', ['profile' => $profile, 'completeness' => app(ProfileCompleteness::class)->forProfile($profile)]);
    }

    public function showProfile(Request $request): View
    {
        $profile = CandidateProfile::firstOrCreate(['user_id' => $request->user()->id], ['full_name' => $request->user()->name]);

        return view('candidate.profile-show', ['profile' => $profile, 'completeness' => app(ProfileCompleteness::class)->forProfile($profile)]);
    }

    public function avatar(Request $request, ProfileAvatarStorage $avatars): BinaryFileResponse
    {
        $profile = CandidateProfile::find($request->user()->id);

        return $avatars->show($profile?->avatar_key, $request->user()->id);
    }

    public function updateProfile(Request $request, ProfileAvatarStorage $avatars): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'university' => 'nullable|string|max:160',
            'major' => 'nullable|string|max:160',
            'study_year' => 'nullable|integer|min:1|max:8',
            'introduction' => 'nullable|string|max:3000',
            'skills_text' => 'nullable|string|max:2000',
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=4096,max_height=4096'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ], [
            'avatar.image' => __('profile.avatar_invalid'),
            'avatar.mimes' => __('profile.avatar_invalid'),
            'avatar.max' => __('profile.avatar_size'),
            'avatar.dimensions' => __('profile.avatar_dimensions'),
        ]);
        $removeAvatar = $request->boolean('remove_avatar');
        if ($removeAvatar && $request->hasFile('avatar')) {
            throw ValidationException::withMessages(['avatar' => __('profile.avatar_conflict')]);
        }
        unset($data['avatar'], $data['remove_avatar']);
        $userId = $request->user()->id;
        $newAvatar = $request->hasFile('avatar') ? $avatars->store($request->file('avatar'), $userId) : null;

        try {
            $oldAvatar = DB::transaction(function () use ($userId, $data, $newAvatar, $removeAvatar): ?string {
                $owner = User::query()->lockForUpdate()->findOrFail($userId);
                abort_unless($owner->status === 'ACTIVE' && $owner->role === 'candidate', 403);
                $profile = CandidateProfile::query()->lockForUpdate()->find($userId);
                $oldAvatar = $profile?->avatar_key;
                $profile ??= new CandidateProfile(['user_id' => $userId]);
                $profile->fill($data);
                if ($newAvatar !== null || $removeAvatar) {
                    $profile->avatar_key = $newAvatar;
                }
                $profile->save();
                $owner->update(['name' => $data['full_name']]);

                return $oldAvatar;
            });
        } catch (\Throwable $exception) {
            $avatars->delete($newAvatar, $userId);
            throw $exception;
        }

        if (($newAvatar !== null || $removeAvatar) && $oldAvatar !== $newAvatar) {
            $avatars->delete($oldAvatar, $userId);
        }

        return back()->with('success', __('messages.profile_saved'));
    }
}
