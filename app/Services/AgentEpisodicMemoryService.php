<?php

namespace App\Services;

use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class AgentEpisodicMemoryService
{
    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly AgentMemoryPolicy $policy,
    ) {}

    /**
     * Record one explicit meaningful Agent experience.
     *
     * The Agent execution remains the authoritative source. Memory stores a
     * concise summary plus an immutable provenance reference to that execution.
     *
     * @param  array<string, mixed>  $provenance
     */
    public function recordMeaningfulEvent(
        User $actor,
        AgentExecution $execution,
        string $objective,
        string $action,
        string $result,
        string $outcome,
        ?string $topic = null,
        ?Carbon $occurredAt = null,
        array $provenance = [],
    ): AgentEpisodicMemory {
        $this->policy->authorizeEpisodicWrite($actor, $execution);

        if ($execution->enterprise_id === null) {
            throw new AuthorizationException('Agent episodic memory requires an enterprise-scoped execution.');
        }

        $enterprise = $execution->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException('Agent episodic memory requires an existing Enterprise.');
        }

        Gate::forUser($actor)->authorize('view', $enterprise);

        $this->validateMeaningfulContent($objective, $action, $result, $outcome);

        $sourceProvenance = [
            ...$provenance,
            'source_type' => AgentExecution::class,
            'source_id' => (int) $execution->getKey(),
        ];

        return AgentEpisodicMemory::query()->create([
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'agent_descriptor_id' => $execution->agent_descriptor_id,
            'execution_id' => $execution->getKey(),
            'topic' => $topic === null ? null : $this->normalizeOptional($topic),
            'objective' => $this->normalizeRequired($objective),
            'action' => $this->normalizeRequired($action),
            'result' => $this->normalizeRequired($result),
            'outcome' => $this->normalizeRequired($outcome),
            'occurred_at' => $occurredAt ?? $execution->completed_at ?? $execution->requested_at ?? now(),
            'provenance' => $sourceProvenance,
        ]);
    }

    /**
     * Retrieve bounded episodic memory within one authorized Enterprise scope.
     *
     * Results are deterministic: topic matches are first, then newest
     * occurrence, then newest record id. The limit is capped server-side.
     *
     * @return Collection<int, AgentEpisodicMemory>
     */
    public function retrieve(
        User $actor,
        Enterprise $enterprise,
        ?AgentDescriptor $agent = null,
        ?string $topic = null,
        ?Carbon $relevantAfter = null,
        int $limit = self::DEFAULT_LIMIT,
    ): Collection {
        if ($agent !== null) {
            $this->policy->authorizeRead($actor, $enterprise, $agent);
        } else {
            Gate::forUser($actor)->authorize('view', $enterprise);
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $topic = $topic === null ? null : $this->normalizeOptional($topic);

        return AgentEpisodicMemory::query()
            ->where('organization_id', $enterprise->organization_id)
            ->where('enterprise_id', $enterprise->getKey())
            ->when($agent !== null, fn ($query) => $query->where('agent_descriptor_id', $agent->getKey()))
            ->when($topic !== null, function ($query) use ($topic): void {
                $query->orderByRaw('CASE WHEN topic = ? THEN 0 ELSE 1 END', [$topic]);
            })
            ->when($relevantAfter !== null, fn ($query) => $query->where('occurred_at', '>=', $relevantAfter))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function validateMeaningfulContent(string $objective, string $action, string $result, string $outcome): void
    {
        foreach ([
            'objective' => $objective,
            'action' => $action,
            'result' => $result,
            'outcome' => $outcome,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("Agent episodic memory {$field} cannot be empty.");
            }
        }
    }

    private function normalizeRequired(string $value): string
    {
        return trim($value);
    }

    private function normalizeOptional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
