<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class AgentAssignmentService
{
    /** @param array<string, mixed> $input */
    public function create(User $actor, Enterprise $enterprise, array $input): AgentAssignment
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $agent = AgentDescriptor::query()->findOrFail((int) $input['agent_descriptor_id']);

        if (! $agent->enabled) {
            throw new InvalidArgumentException('Agent Assignment requires an enabled Agent descriptor.');
        }

        $assignment = new AgentAssignment([
            'agent_descriptor_id' => $agent->getKey(),
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'enabled' => true,
            'status' => $input['status'] ?? AgentAssignment::STATUS_DRAFT,
            'objective' => $input['objective'] ?? null,
            'requirements' => $input['requirements'] ?? [],
            'context' => $input['context'] ?? [],
            'correlation_id' => $input['correlation_id'] ?? null,
            'idempotency_key' => $input['idempotency_key'] ?? null,
        ]);

        Gate::forUser($actor)->authorize('createForAgentAssignment', $assignment);

        if ($assignment->idempotency_key !== null) {
            $existing = AgentAssignment::query()
                ->where('enterprise_id', $enterprise->getKey())
                ->where('idempotency_key', $assignment->idempotency_key)
                ->first();

            if ($existing !== null) {
                return $existing->load(['agentDescriptor', 'enterprise']);
            }
        }

        $assignment->save();

        return $assignment->load(['agentDescriptor', 'enterprise']);
    }

    public function get(User $actor, Enterprise $enterprise, AgentAssignment $assignment): AgentAssignment
    {
        $this->authorizeScope($actor, $enterprise, $assignment);

        return $assignment->load(['agentDescriptor', 'enterprise']);
    }

    /** @return Collection<int, AgentAssignment> */
    public function list(
        User $actor,
        Enterprise $enterprise,
        ?int $agentDescriptorId = null,
        ?string $status = null,
        int $limit = 50,
    ): Collection {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return AgentAssignment::query()
            ->with(['agentDescriptor', 'enterprise'])
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->when($agentDescriptorId !== null, fn ($q) => $q->where('agent_descriptor_id', $agentDescriptorId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->limit(min(50, max(1, $limit)))
            ->get();
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, Enterprise $enterprise, AgentAssignment $assignment, array $input): AgentAssignment
    {
        $this->authorizeScope($actor, $enterprise, $assignment);
        Gate::forUser($actor)->authorize('update', $assignment);

        if (array_key_exists('agent_descriptor_id', $input)) {
            $agent = AgentDescriptor::query()->findOrFail((int) $input['agent_descriptor_id']);

            if (! $agent->enabled) {
                throw new InvalidArgumentException('Agent Assignment requires an enabled Agent descriptor.');
            }

            $assignment->agent_descriptor_id = $agent->getKey();
        }

        foreach (['objective', 'requirements', 'context', 'correlation_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $assignment->{$field} = $input[$field];
            }
        }

        $assignment->save();

        return $assignment->refresh()->load(['agentDescriptor', 'enterprise']);
    }

    public function transition(
        User $actor,
        Enterprise $enterprise,
        AgentAssignment $assignment,
        string $status,
    ): AgentAssignment {
        $this->authorizeScope($actor, $enterprise, $assignment);
        Gate::forUser($actor)->authorize('update', $assignment);

        if (! in_array($status, AgentAssignment::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid Agent Assignment status [{$status}].");
        }

        $allowed = AgentAssignment::transitions()[$assignment->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw new InvalidArgumentException("Agent Assignment cannot transition from [{$assignment->status}] to [{$status}].");
        }

        DB::transaction(function () use ($assignment, $status): void {
            $assignment->status = $status;
            $assignment->enabled = ! in_array($status, [AgentAssignment::STATUS_COMPLETED, AgentAssignment::STATUS_CANCELLED], true);

            if ($status === AgentAssignment::STATUS_RUNNING && $assignment->started_at === null) {
                $assignment->started_at = now();
            }

            if (in_array($status, [AgentAssignment::STATUS_COMPLETED, AgentAssignment::STATUS_FAILED, AgentAssignment::STATUS_CANCELLED], true)) {
                $assignment->completed_at = now();
            }

            $assignment->save();
        });

        return $assignment->refresh()->load(['agentDescriptor', 'enterprise']);
    }

    /** @return array<string, mixed> */
    public function serialize(AgentAssignment $assignment): array
    {
        return [
            'id' => $assignment->getKey(),
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $assignment->enterprise_id,
            'agent_descriptor_id' => $assignment->agent_descriptor_id,
            'enabled' => $assignment->enabled,
            'status' => $assignment->status,
            'objective' => $assignment->objective,
            'requirements' => $assignment->requirements ?? [],
            'context' => $assignment->context ?? [],
            'correlation_id' => $assignment->correlation_id,
            'idempotency_key' => $assignment->idempotency_key,
            'started_at' => $assignment->started_at !== null ? \Carbon\CarbonImmutable::parse((string) $assignment->started_at)->toISOString() : null,
            'completed_at' => $assignment->completed_at !== null ? \Carbon\CarbonImmutable::parse((string) $assignment->completed_at)->toISOString() : null,
            'agent' => $assignment->agentDescriptor ? [
                'id' => $assignment->agentDescriptor->getKey(),
                'slug' => $assignment->agentDescriptor->slug,
                'enabled' => $assignment->agentDescriptor->enabled,
            ] : null,
        ];
    }

    private function authorizeScope(User $actor, Enterprise $enterprise, AgentAssignment $assignment): void
    {
        if (
            (int) $assignment->enterprise_id !== (int) $enterprise->getKey()
            || (int) $assignment->organization_id !== (int) $enterprise->organization_id
        ) {
            throw new InvalidArgumentException('Agent Assignment does not belong to the requested Enterprise.');
        }

        Gate::forUser($actor)->authorize('view', $assignment);
    }
}
