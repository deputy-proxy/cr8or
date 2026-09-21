<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->sentence(4);

        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => $name,
            'description' => fake()->optional()->paragraph(),
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}