<?php

namespace Database\Factories;

use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentEpisodicMemory> */
class AgentEpisodicMemoryFactory extends Factory
{
    public function definition(): array
    {
        $execution = AgentExecution::factory()->forEnterprise()->create();
        $descriptor = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

        return [
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'agent_descriptor_id' => $descriptor->getKey(),
            'execution_id' => $execution->getKey(),
            'topic' => fake()->words(2, true),
            'objective' => fake()->sentence(),
            'action' => fake()->sentence(),
            'result' => fake()->sentence(),
            'outcome' => fake()->sentence(),
            'occurred_at' => now(),
            'provenance' => [
                'source_type' => AgentExecution::class,
                'source_id' => $execution->getKey(),
            ],
        ];
    }

    public function forExecution(AgentExecution $execution): static
    {
        return $this->state(function () use ($execution): array {
            $descriptor = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

            return [
                'organization_id' => $execution->organization_id,
                'enterprise_id' => $execution->enterprise_id,
                'agent_descriptor_id' => $descriptor->getKey(),
                'execution_id' => $execution->getKey(),
                'provenance' => [
                    'source_type' => AgentExecution::class,
                    'source_id' => $execution->getKey(),
                ],
            ];
        });
    }

    public function forEnterprise(Enterprise $enterprise): static
    {
        return $this->state(function () use ($enterprise): array {
            $execution = AgentExecution::factory()->forEnterprise($enterprise)->create();
            $descriptor = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

            return [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
                'agent_descriptor_id' => $descriptor->getKey(),
                'execution_id' => $execution->getKey(),
                'provenance' => [
                    'source_type' => AgentExecution::class,
                    'source_id' => $execution->getKey(),
                ],
            ];
        });
    }

    public function forAgent(AgentDescriptor $agent, Enterprise $enterprise): static
    {
        return $this->state(function () use ($agent, $enterprise): array {
            $execution = AgentExecution::factory()
                ->forEnterprise($enterprise)
                ->create(['agent_descriptor_id' => $agent->getKey()]);

            return [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
                'agent_descriptor_id' => $agent->getKey(),
                'execution_id' => $execution->getKey(),
                'provenance' => [
                    'source_type' => AgentExecution::class,
                    'source_id' => $execution->getKey(),
                ],
            ];
        });
    }
}