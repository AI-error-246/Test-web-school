<?php

namespace Database\Factories;

use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobApplication> */
class JobApplicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['job_id' => JobPosting::factory()->accepting(), 'cv_id' => CvDocument::factory(), 'candidate_id' => fn (array $attributes) => CvDocument::findOrFail($attributes['cv_id'])->candidate_id, 'name_snapshot' => 'Nguyễn Minh Anh', 'email_snapshot' => 'candidate@example.test', 'phone_snapshot' => '0901234567', 'university_snapshot' => 'Đại học Công nghệ', 'major_snapshot' => 'Công nghệ thông tin', 'job_title_snapshot' => fn (array $attributes) => JobPosting::findOrFail($attributes['job_id'])->title, 'company_name_snapshot' => fn (array $attributes) => JobPosting::findOrFail($attributes['job_id'])->company->name, 'status' => 'SUBMITTED', 'version' => 1, 'applied_at' => now()];
    }
}
