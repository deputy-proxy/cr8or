<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowVersion> */
class WorkflowVersionFactory extends Factory
{
    protected $model = WorkflowVersion::class;

    public function definition(): array
    {
        $enterprise = Enterprise::factory();

        return [
            'workflow_id' => Workflow::factory()->state(['enterprise_id' => $enterprise]),
            'enterprise_id' => $enterprise,
            'version' => 1,
            'status' => WorkflowVersion::STATUS_DRAFT,
            'name' => fake()->sentence(3),
            'purpose' => fake()->sentence(8),
            'execution_policy' => [],
            'completion_criteria' => [],
            'stage_definitions' => [],
            'idempotency_key' => fake()->unique()->uuid(),
            'created_by' => User::factory(),
        ];
    }
}