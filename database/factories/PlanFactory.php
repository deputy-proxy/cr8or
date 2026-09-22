<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'strategy_id' => Strategy::factory(),
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}
