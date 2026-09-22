<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'project_id' => null, 'parent_task_id' => null, 'name' => fake()->sentence(3), 'description' => fake()->optional()->paragraph(), 'status' => 'todo', 'priority' => 'normal', 'due_at' => null];
    }

    public function forProject(Project $project): static
    {
        return $this->state(['enterprise_id' => $project->enterprise_id, 'project_id' => $project->id]);
    }
}
