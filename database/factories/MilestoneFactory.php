<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Milestone> */
class MilestoneFactory extends Factory
{
    protected $model = Milestone::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'project_id' => null, 'name' => fake()->sentence(2), 'description' => fake()->optional()->paragraph(), 'due_at' => null, 'status' => 'planned'];
    }

    public function forProject(Project $project): static
    {
        return $this->state(['enterprise_id' => $project->enterprise_id, 'project_id' => $project->id]);
    }
}
