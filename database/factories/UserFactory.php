<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'role' => 'candidate', 'status' => 'ACTIVE', 'is_demo' => false];
    }

    public function candidate(): static
    {
        return $this->state(fn (): array => ['role' => 'candidate']);
    }

    public function employer(): static
    {
        return $this->state(fn (): array => ['role' => 'employer']);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => 'admin']);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => 'BLOCKED']);
    }
}
