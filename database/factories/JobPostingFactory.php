<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\JobCategory;
use App\Models\JobPosting;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobPosting> */
class JobPostingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'category_id' => JobCategory::factory(), 'location_id' => Location::factory(), 'title' => 'Thực tập sinh lập trình Laravel', 'description' => 'Tham gia xây dựng và kiểm thử tính năng dưới sự hướng dẫn của mentor.', 'requirements' => 'Sinh viên năm 3 hoặc 4; có kiến thức nền tảng lập trình.', 'benefits' => 'Hướng dẫn chuyên môn và xác nhận thực tập.', 'work_mode' => 'HYBRID', 'allowance_min' => 2000000, 'allowance_max' => 4000000, 'currency' => 'VND', 'deadline_at' => now()->addMonth(), 'publication_state' => 'DRAFT', 'moderation_state' => 'NOT_SUBMITTED', 'published_at' => null, 'version' => 1];
    }

    public function accepting(): static
    {
        return $this->state(fn (): array => ['publication_state' => 'OPEN', 'moderation_state' => 'APPROVED', 'published_at' => now()]);
    }

    public function approved(): static
    {
        return $this->accepting();
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['publication_state' => 'DRAFT', 'moderation_state' => 'PENDING']);
    }

    public function closed(): static
    {
        return $this->accepting()->state(fn (): array => ['publication_state' => 'CLOSED']);
    }
}
