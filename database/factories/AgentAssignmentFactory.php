<?php

namespace Database\Factories;

use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentAssignment> */
class AgentAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_descriptor_id' => AgentDescriptor::factory(),
            'organization_id' => Organization::factory(),
            'enterprise_id' => null,
            'enabled' => true,
        ];
    }

    public function forEnterprise(?Enterprise $enterprise = null): static
    {
        return $this->state(function () use ($enterprise): array {
            $enterprise ??= Enterprise::factory()->create();

            return [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
            ];
        });
    }

    public function disabled(): static
    {
        return $this->state([
            'enabled' => false,
        ]);
    }
}
