<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['owner_user_id' => User::factory()->employer(), 'name' => fake()->company(), 'description' => 'Doanh nghiệp phát triển sản phẩm số và đào tạo sinh viên.', 'address' => 'Quận 1, Thành phố Hồ Chí Minh', 'contact_email' => fake()->safeEmail(), 'website' => 'https://example.com', 'visibility' => 'VISIBLE'];
    }
}
