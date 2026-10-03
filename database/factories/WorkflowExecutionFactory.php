<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowExecution> */
class WorkflowExecutionFactory extends Factory
{
    protected $model = WorkflowExecution::class;

    public function definition(): array
    {
        $enterprise = Enterprise::factory();
        $workflow = Workflow::factory()->state(['enterprise_id' => $enterprise]);

        return [
            'workflow_id' => $workflow,
            'workflow_version_id' => WorkflowVersion::factory()->state([
                'workflow_id' => $workflow,
                'enterprise_id' => $enterprise,
                'status' => WorkflowVersion::STATUS_PUBLISHED,
                'stage_definitions' => [],
            ]),
            'workflow_version' => 1,
            'enterprise_id' => $enterprise,
            'actor_id' => User::factory(),
            'status' => WorkflowExecution::STATUS_PENDING,
            'correlation_id' => fake()->uuid(),
            'idempotency_key' => fake()->unique()->uuid(),
            'continuation_token' => fake()->uuid(),
            'input' => [],
            'outputs' => [],
            'context' => [],
        ];
    }

    public function forEnterprise(Enterprise $enterprise): static
    {
        return $this->state([
            'enterprise_id' => $enterprise,
        ]);
    }
}