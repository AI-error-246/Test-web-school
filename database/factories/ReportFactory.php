<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['reporter_id' => User::factory()->candidate(), 'job_id' => JobPosting::factory()->accepting(), 'reason' => 'Thông tin cần xác minh', 'description' => 'Mức trợ cấp trong mô tả chưa thống nhất.', 'status' => 'OPEN'];
    }
}
