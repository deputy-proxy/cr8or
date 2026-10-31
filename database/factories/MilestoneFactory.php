<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Milestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Milestone> */
class MilestoneFactory extends Factory
{
    protected $model = Milestone::class;

    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => fake()->sentence(2),
            'description' => fake()->optional()->paragraph(),
            'target_date' => null,
            'status' => 'planned',
        ];
    }
}
