<?php

namespace Database\Factories;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CandidateProfile> */
class CandidateProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory()->candidate(), 'full_name' => fake()->name(), 'phone' => '0901234567', 'university' => 'Đại học Công nghệ', 'major' => 'Công nghệ thông tin', 'study_year' => 3, 'skills_text' => 'PHP, SQL', 'introduction' => 'Mong muốn học hỏi qua kỳ thực tập.'];
    }
}
