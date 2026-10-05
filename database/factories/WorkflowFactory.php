<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Workflow> */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function generic(): static
    {
        return $this->state([
            'enterprise_specific' => false,
            'enterprise_id' => null,
        ]);
    }

    public function definition(): array
    {
        return [
            'enterprise_specific' => true,
            'enterprise_id' => Enterprise::factory(),
            'project_id' => null,
            'task_id' => null,
            'work_item_id' => null,
            'name' => fake()->sentence(3),
            'status' => Workflow::STATUS_PENDING,
        ];
    }
}