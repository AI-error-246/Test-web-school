<?php

namespace App\Services;

use App\Models\CandidateProfile;
use App\Models\CvDocument;

class ProfileCompleteness
{
    /** @return array{percent: int, completed: int, total: int, missing: list<string>} */
    public function forProfile(CandidateProfile $profile): array
    {
        $checks = [];
        foreach (['full_name' => __('messages.profile_name'), 'phone' => __('messages.profile_phone'), 'university' => __('messages.profile_university'), 'major' => __('messages.profile_major'), 'study_year' => __('messages.profile_year'), 'introduction' => __('messages.profile_intro'), 'skills_text' => __('messages.profile_skills')] as $field => $label) {
            $checks[$label] = trim((string) $profile->{$field}) !== '';
        }
        $checks[__('messages.profile_cv')] = CvDocument::where('candidate_id', $profile->user_id)->whereNull('archived_at')->exists();
        $completed = count(array_filter($checks));

        return ['percent' => (int) round($completed / count($checks) * 100), 'completed' => $completed, 'total' => count($checks), 'missing' => array_keys(array_filter($checks, fn (bool $done): bool => ! $done))];
    }
}
