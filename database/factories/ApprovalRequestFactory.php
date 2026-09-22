<?php

namespace Database\Factories;

use App\Models\AgentAssignment;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApprovalRequest> */
class ApprovalRequestFactory extends Factory
{
    public function definition(): array
    {
        $assignment = AgentAssignment::factory()->create();
        $actor = User::factory()->create();

        return [
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $assignment->enterprise_id,
            'agent_assignment_id' => $assignment->getKey(),
            'agent_execution_id' => null,
            'actor_id' => $actor->getKey(),
            'approver_id' => null,
            'capability' => fake()->unique()->slug(2, '.'),
            'target_context' => ['resource_id' => fake()->uuid()],
            'organization_name' => $assignment->organization->name,
            'enterprise_name' => $assignment->enterprise?->name,
            'agent_slug' => $assignment->agentDescriptor->slug,
            'agent_runtime_class' => $assignment->agentDescriptor->runtime_class,
            'actor_name' => $actor->name,
            'approver_name' => null,
            'status' => ApprovalRequest::STATUS_PENDING,
            'requested_at' => now(),
            'expires_at' => now()->addHour(),
            'decided_at' => null,
            'decision_reason' => null,
        ];
    }
}
