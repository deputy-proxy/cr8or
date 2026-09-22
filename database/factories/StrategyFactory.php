<?php

namespace Database\Factories;

use App\Models\Objective;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Strategy> */
class StrategyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'objective_id' => Objective::factory(),
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}

