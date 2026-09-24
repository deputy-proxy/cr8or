<?php

namespace Database\Factories;

use App\Agents\CeoAgent;
use App\Agents\FinanceAgent;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentDelegation> */
class AgentDelegationFactory extends Factory
{
    protected $model = AgentDelegation::class;

    public function definition(): array
    {
        $enterprise = Enterprise::factory()->create();
        $sourceDescriptor = AgentDescriptor::query()->firstOrCreate(
            ['runtime_class' => CeoAgent::class],
            ['slug' => fake()->unique()->slug(2), 'enabled' => true],
        );
        $targetDescriptor = AgentDescriptor::query()->firstOrCreate(
            ['runtime_class' => FinanceAgent::class],
            ['slug' => fake()->unique()->slug(2), 'enabled' => true],
        );
        $source = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $sourceDescriptor->getKey()]);
        $target = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $targetDescriptor->getKey()]);
        $actor = User::factory()->create();

        return [
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'source_agent_assignment_id' => $source->getKey(),
            'target_agent_assignment_id' => $target->getKey(),
            'parent_agent_execution_id' => null,
            'actor_id' => $actor->getKey(),
            'organization_name' => $enterprise->organization->name,
            'enterprise_name' => $enterprise->name,
            'source_agent_slug' => $source->agentDescriptor->slug,
            'source_agent_runtime_class' => $source->agentDescriptor->runtime_class,
            'target_agent_slug' => $target->agentDescriptor->slug,
            'target_agent_runtime_class' => $target->agentDescriptor->runtime_class,
            'actor_name' => $actor->name,
            'capability' => 'work.create',
            'prompt' => fake()->sentence(),
            'target_context' => ['enterprise_id' => $enterprise->getKey()],
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'attempts' => 0,
            'status' => AgentDelegation::STATUS_PENDING,
            'requested_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
        ];
    }

    public function forParentExecution(AgentExecution $execution): static
    {
        return $this->state([
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'source_agent_assignment_id' => $execution->agent_assignment_id,
            'parent_agent_execution_id' => $execution->getKey(),
            'organization_name' => $execution->organization_name,
            'enterprise_name' => $execution->enterprise_name,
        ]);
    }

    public function running(): static
    {
        return $this->state(['status' => AgentDelegation::STATUS_RUNNING, 'started_at' => now()]);
    }

    public function failed(string $reason = 'Delegation failed'): static
    {
        return $this->state(['status' => AgentDelegation::STATUS_FAILED, 'completed_at' => now(), 'failure_reason' => $reason]);
    }

    public function succeeded(): static
    {
        return $this->state(['status' => AgentDelegation::STATUS_SUCCEEDED, 'completed_at' => now()]);
    }
}
