<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowStage> */
class WorkflowStageFactory extends Factory
{
    protected $model = WorkflowStage::class;

    public function definition(): array
    {
        return ['workflow_id' => Workflow::factory(), 'key' => fake()->unique()->slug(2), 'name' => fake()->sentence(3), 'sequence' => 1, 'dependencies' => [], 'expert_slugs' => [], 'capability_slugs' => [], 'input_contract' => [], 'output_contract' => [], 'repeatable' => false, 'completion_criteria' => ['requires_termination_completed' => true]];
    }
}