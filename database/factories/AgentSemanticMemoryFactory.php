<?php

namespace Database\Factories;

use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentSemanticMemory> */
class AgentSemanticMemoryFactory extends Factory
{
    public function definition(): array
    {
        $execution = AgentExecution::factory()->forEnterprise()->create();
        $descriptor = AgentDescriptor::query()->findOrFail($execution->agent_descriptor_id);

        return [
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'agent_descriptor_id' => $descriptor->getKey(),
            'statement' => fake()->sentence(),
            'confidence' => fake()->randomFloat(4, 0, 1),
            'status' => AgentSemanticMemory::STATUS_ACTIVE,
            'conflict_memory_ids' => null,
            'provenance' => [
                'source_type' => AgentExecution::class,
                'source_id' => $execution->getKey(),
            ],
        ];
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
            $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
                'agent_descriptor_id' => $agent->getKey(),
            ]);

            return [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
                'agent_descriptor_id' => $agent->getKey(),
                'provenance' => [
                    'source_type' => AgentExecution::class,
                    'source_id' => $execution->getKey(),
                ],
            ];
        });
    }
}
