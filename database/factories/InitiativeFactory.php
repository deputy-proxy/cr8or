<?php

namespace Database\Factories;

use App\Models\Initiative;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Initiative> */
class InitiativeFactory extends Factory
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
