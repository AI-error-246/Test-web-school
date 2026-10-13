<?php

namespace Database\Factories;

use App\Models\CandidateProfile;
use App\Models\CvDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CvDocument> */
class CvDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['candidate_id' => CandidateProfile::factory(), 'display_name' => 'CV thực tập', 'storage_key' => 'cvs/'.fake()->uuid().'.pdf', 'original_filename' => 'cv.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024, 'uploaded_at' => now(), 'archived_at' => null];
    }
}
