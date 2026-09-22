<?php

namespace Database\Factories;

use App\Agents\Agent;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentExecution> */
class AgentExecutionFactory extends Factory
{
    public function definition(): array
    {
        $organization = Organization::factory()->create();
        $descriptor = AgentDescriptor::query()->firstOrCreate(
            ['runtime_class' => Agent::class],
            ['slug' => fake()->unique()->slug(2), 'enabled' => true],
        );
        $actor = User::factory()->create();

        return [
            'organization_id' => $organization,
            'enterprise_id' => null,
            'agent_descriptor_id' => $descriptor,
            'agent_assignment_id' => null,
            'actor_id' => $actor,
            'organization_name' => $organization->name,
            'enterprise_name' => null,
            'agent_slug' => $descriptor->slug,
            'agent_runtime_class' => $descriptor->runtime_class,
            'actor_name' => $actor->name,
            'status' => AgentExecution::STATUS_REQUESTED,
            'requested_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
        ];
    }

    public function forEnterprise(?Enterprise $enterprise = null): static
    {
        return $this->state(function () use ($enterprise): array {
            $enterprise ??= Enterprise::factory()->create();

            return [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
                'organization_name' => $enterprise->organization->name,
                'enterprise_name' => $enterprise->name,
            ];
        });
    }

    public function forAssignment(AgentAssignment $assignment): static
    {
        return $this->state(function () use ($assignment): array {
            $descriptor = $assignment->agentDescriptor;

            return [
                'organization_id' => $assignment->organization_id,
                'enterprise_id' => $assignment->enterprise_id,
                'agent_descriptor_id' => $assignment->agent_descriptor_id,
                'agent_assignment_id' => $assignment->getKey(),
                'organization_name' => $assignment->organization->name,
                'enterprise_name' => $assignment->enterprise?->name,
                'agent_slug' => $descriptor->slug,
                'agent_runtime_class' => $descriptor->runtime_class,
            ];
        });
    }

    public function executing(): static
    {
        return $this->state(['status' => AgentExecution::STATUS_EXECUTING, 'started_at' => now()]);
    }
}