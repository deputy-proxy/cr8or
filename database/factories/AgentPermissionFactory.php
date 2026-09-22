<?php

namespace Database\Factories;

use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentPermission> */
class AgentPermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_assignment_id' => AgentAssignment::factory(),
            'capability' => fake()->unique()->slug(2, '.'),
        ];
    }
}
