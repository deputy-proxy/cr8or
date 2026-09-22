<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkItem> */
class WorkItemFactory extends Factory
{
    protected $model = WorkItem::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'project_id' => null, 'name' => fake()->sentence(3), 'description' => fake()->optional()->paragraph(), 'status' => 'todo'];
    }
}
