<?php

namespace Database\Factories;

use App\Models\AgentExecution;
use App\Models\AgentExecutionStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentExecutionStep> */
class AgentExecutionStepFactory extends Factory
{
    protected $model = AgentExecutionStep::class;

    public function definition(): array
    {
        $execution = AgentExecution::factory()->forEnterprise()->create();

        return [
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'agent_execution_id' => $execution->getKey(),
            'sequence' => 1,
            'status' => AgentExecutionStep::STATUS_PENDING,
            'type' => AgentExecutionStep::TYPE_REASONING,
            'intent' => null,
            'input_context' => [],
            'output' => null,
            'capability_requests' => [],
            'failure_reason' => null,
            'failure_code' => null,
            'correlation_id' => $execution->correlation_id,
            'idempotency_key' => 'step-'.$this->faker->uuid(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}