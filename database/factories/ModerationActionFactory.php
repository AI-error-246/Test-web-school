<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModerationAction> */
class ModerationActionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['admin_id' => User::factory()->admin(), 'job_id' => JobPosting::factory(), 'company_id' => null, 'action' => 'APPROVE', 'from_state' => 'PENDING', 'to_state' => 'APPROVED', 'reason' => 'Nội dung đầy đủ.', 'reviewed_version' => 1, 'created_at' => now()];
    }
}
