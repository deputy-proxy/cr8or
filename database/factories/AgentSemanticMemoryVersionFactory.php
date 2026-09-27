<?php

namespace Database\Factories;

use App\Models\AgentSemanticMemory;
use App\Models\AgentSemanticMemoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentSemanticMemoryVersion> */
class AgentSemanticMemoryVersionFactory extends Factory
{
    public function definition(): array
    {
        $memory = AgentSemanticMemory::factory()->create();

        return [
            'agent_semantic_memory_id' => $memory->getKey(),
            'organization_id' => $memory->organization_id,
            'enterprise_id' => $memory->enterprise_id,
            'agent_descriptor_id' => $memory->agent_descriptor_id,
            'statement' => $memory->statement,
            'confidence' => $memory->confidence,
            'status' => $memory->status,
            'conflict_memory_ids' => $memory->conflict_memory_ids,
            'provenance' => $memory->provenance,
            'change_type' => 'created',
            'changed_by_user_id' => null,
            'recorded_at' => now(),
        ];
    }
}
