<?php

namespace Database\Factories;

use App\Agents\Agent;
use App\Models\AgentDecision;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentDecision> */
class AgentDecisionFactory extends Factory
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
            'execution_id' => null,
            'agent_descriptor_id' => $descriptor,
            'actor_id' => $actor,
            'organization_name' => $organization->name,
            'enterprise_name' => null,
            'agent_slug' => $descriptor->slug,
            'agent_runtime_class' => $descriptor->runtime_class,
            'actor_name' => $actor->name,
            'title' => fake()->sentence(6),
            'summary' => fake()->paragraph(),
            'rationale' => fake()->optional()->paragraph(),
            'decided_at' => now(),
        ];
    }

    public function forExecution(AgentExecution $execution): static
    {
        return $this->state([
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'execution_id' => $execution->getKey(),
            'agent_descriptor_id' => $execution->agent_descriptor_id,
            'actor_id' => $execution->actor_id,
            'organization_name' => $execution->organization_name,
            'enterprise_name' => $execution->enterprise_name,
            'agent_slug' => $execution->agent_slug,
            'agent_runtime_class' => $execution->agent_runtime_class,
            'actor_name' => $execution->actor_name,
        ]);
    }
}
