<?php

namespace Database\Factories;

use App\Models\Initiative;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Initiative> */
class InitiativeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}
