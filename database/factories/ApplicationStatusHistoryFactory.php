<?php

namespace Database\Factories;

use App\Models\ApplicationStatusHistory;
use App\Models\JobApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApplicationStatusHistory> */
class ApplicationStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['application_id' => JobApplication::factory(), 'changed_by' => fn (array $attributes) => JobApplication::findOrFail($attributes['application_id'])->candidate_id, 'from_status' => null, 'to_status' => 'SUBMITTED', 'changed_at' => now()];
    }
}
