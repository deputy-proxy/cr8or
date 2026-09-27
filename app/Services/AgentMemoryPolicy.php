<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class AgentMemoryPolicy
{
    public const TYPE_EPISODIC = 'episodic';

    public const TYPE_SEMANTIC = 'semantic';

    public function authorizeRead(
        User $actor,
        Enterprise $enterprise,
        AgentDescriptor $agent,
    ): void {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if (! $agent->exists || ! $agent->enabled) {
            throw new AuthorizationException('Agent memory requires an enabled Agent descriptor.');
        }
    }

    public function authorizeEpisodicWrite(
        User $actor,
        AgentExecution $execution,
    ): void {
        Gate::forUser($actor)->authorize('view', $execution);

        if (! in_array($execution->status, [
            AgentExecution::STATUS_SUCCEEDED,
            AgentExecution::STATUS_FAILED,
        ], true)) {
            throw new AuthorizationException(
                'Agent episodic memory may only be written from a terminal Agent execution.',
            );
        }

        $this->authorizeExecutionScope($actor, $execution);
    }

    public function authorizeSemanticWrite(
        User $actor,
        AgentExecution $execution,
    ): void {
        Gate::forUser($actor)->authorize('view', $execution);

        if ($execution->status !== AgentExecution::STATUS_SUCCEEDED) {
            throw new AuthorizationException(
                'Agent semantic memory may only be written from a successful Agent execution.',
            );
        }

        $this->authorizeExecutionScope($actor, $execution);
    }

    public function authorizeSemanticUpdate(
        User $actor,
        AgentSemanticMemory $memory,
        AgentExecution $sourceExecution,
    ): void {
        $this->authorizeSemanticWrite($actor, $sourceExecution);

        if (
            $memory->organization_id !== $sourceExecution->organization_id
            || $memory->enterprise_id !== $sourceExecution->enterprise_id
            || $memory->agent_descriptor_id !== $sourceExecution->agent_descriptor_id
        ) {
            throw new AuthorizationException(
                'Agent semantic memory updates must remain within the source Agent Enterprise scope.',
            );
        }

        $this->authorizeRead($actor, $memory->enterprise, $memory->agentDescriptor);
    }

    private function authorizeExecutionScope(User $actor, AgentExecution $execution): void
    {
        if (
            $execution->enterprise_id === null
            || $execution->agent_descriptor_id === null
        ) {
            throw new AuthorizationException(
                'Agent memory requires an Enterprise-scoped Agent execution.',
            );
        }

        $assignment = $execution->agentAssignment;

        if ($execution->agent_assignment_id !== null) {
            if (
                ! $assignment instanceof AgentAssignment
                || ! $assignment->enabled
                || $assignment->organization_id !== $execution->organization_id
                || $assignment->enterprise_id !== $execution->enterprise_id
                || $assignment->agent_descriptor_id !== $execution->agent_descriptor_id
                || ! $assignment->agentDescriptor->enabled
            ) {
                throw new AuthorizationException(
                    'Agent memory requires the execution to belong to its enabled Agent Enterprise assignment.',
                );
            }

            Gate::forUser($actor)->authorize('view', $assignment);

            return;
        }

        Gate::forUser($actor)->authorize('view', $execution->enterprise);
    }
}
