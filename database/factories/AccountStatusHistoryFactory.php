<?php

namespace Database\Factories;

use App\Models\AccountStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountStatusHistory> */
class AccountStatusHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['target_user_id' => User::factory()->candidate(), 'admin_id' => User::factory()->admin(), 'from_status' => 'ACTIVE', 'to_status' => 'BLOCKED', 'reason' => 'Kiểm tra phản ánh vi phạm.', 'created_at' => now()];
    }
}
