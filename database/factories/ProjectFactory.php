<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->sentence(3), 'description' => fake()->optional()->paragraph(), 'status' => 'planned'];
    }
}
