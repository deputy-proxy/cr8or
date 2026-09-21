<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Kpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Kpi> */
class KpiFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => fake()->sentence(3),
            'definition' => fake()->sentence(),
            'unit' => fake()->optional()->randomElement(['percent', 'count', 'currency', 'days']),
            'target_value' => fake()->optional()->randomFloat(4, 0, 1000),
            'current_value' => fake()->optional()->randomFloat(4, 0, 1000),
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}