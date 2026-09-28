<?php

namespace App\Services;

use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class MemoryResourceService
{
    public function __construct(
        private readonly AgentMemoryService $memory,
        private readonly AgentSemanticMemoryService $semantic,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(User $actor, Enterprise $enterprise, array $input): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $type = (string) $input['type'];
        $execution = AgentExecution::query()->findOrFail((int) $input['source_execution_id']);

        if ((int) $execution->enterprise_id !== (int) $enterprise->getKey()) {
            throw new InvalidArgumentException('Memory source execution does not belong to the requested Enterprise.');
        }

        $provenance = [
            'source_type' => AgentExecution::class,
            'source_id' => (int) $execution->getKey(),
            'source_step' => $input['source_step'] ?? null,
            'reason' => $input['reason'] ?? 'explicit_memory_record',
        ];

        if ($type === AgentMemoryPolicy::TYPE_EPISODIC) {
            $memory = $this->memory->recordEpisodic(
                $actor,
                $execution,
                (string) $input['objective'],
                (string) $input['action'],
                (string) $input['result'],
                (string) $input['outcome'],
                $input['topic'] ?? null,
                isset($input['occurred_at']) ? Carbon::parse((string) $input['occurred_at']) : null,
                $provenance,
            );

            return $this->serialize($memory);
        }

        if ($type === AgentMemoryPolicy::TYPE_SEMANTIC) {
            $agent = AgentDescriptor::query()->findOrFail((int) $input['agent_descriptor_id']);
            $this->assertAgentScope($agent, $enterprise, $execution);

            return $this->serialize($this->memory->rememberSemantic(
                $actor,
                $enterprise,
                $agent,
                (string) $input['statement'],
                (float) $input['confidence'],
                $provenance,
            ));
        }

        throw new InvalidArgumentException('Memory type must be episodic or semantic.');
    }

    /** @return array<string, mixed> */
    public function get(User $actor, Enterprise $enterprise, string $type, int $id): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        return $this->serialize($this->find($enterprise, $type, $id));
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>} */
    public function list(
        User $actor,
        Enterprise $enterprise,
        ?string $type = null,
        ?int $agentDescriptorId = null,
        ?string $search = null,
        ?string $status = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        Gate::forUser($actor)->authorize('view', $enterprise);
        $perPage = min(50, max(1, $perPage));
        $page = max(1, $page);

        $episodic = collect();
        $semantic = collect();

        if ($type === null || $type === AgentMemoryPolicy::TYPE_EPISODIC) {
            $episodic = AgentEpisodicMemory::query()
                ->where('organization_id', $enterprise->organization_id)
                ->where('enterprise_id', $enterprise->getKey())
                ->when($agentDescriptorId !== null, fn ($q) => $q->where('agent_descriptor_id', $agentDescriptorId))
                ->when($search !== null && trim($search) !== '', function ($q) use ($search): void {
                    $term = '%'.mb_strtolower(trim($search)).'%';
                    $q->where(function ($nested) use ($term): void {
                        $nested->whereRaw('LOWER(topic) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(objective) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(action) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(result) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(outcome) LIKE ?', [$term]);
                    });
                })
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AgentEpisodicMemory $item): array => $this->serialize($item));
        }

        if ($type === null || $type === AgentMemoryPolicy::TYPE_SEMANTIC) {
            $semantic = AgentSemanticMemory::query()
                ->where('organization_id', $enterprise->organization_id)
                ->where('enterprise_id', $enterprise->getKey())
                ->when($agentDescriptorId !== null, fn ($q) => $q->where('agent_descriptor_id', $agentDescriptorId))
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->when($search !== null && trim($search) !== '', fn ($q) => $q->whereRaw('LOWER(statement) LIKE ?', ['%'.mb_strtolower(trim($search)).'%']))
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AgentSemanticMemory $item): array => $this->serialize($item));
        }

        $items = $episodic->concat($semantic)->values();
        $total = $items->count();
        $slice = $items->forPage($page, $perPage)->values();

        return [
            'items' => array_values($slice->all()),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(User $actor, Enterprise $enterprise, array $input): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        if (($input['type'] ?? null) !== AgentMemoryPolicy::TYPE_SEMANTIC) {
            throw new InvalidArgumentException('Only semantic Memory supports explicit updates; episodic Memory is immutable.');
        }

        $memory = AgentSemanticMemory::query()->findOrFail((int) $input['memory_id']);

        if ((int) $memory->enterprise_id !== (int) $enterprise->getKey()) {
            throw new InvalidArgumentException('Memory does not belong to the requested Enterprise.');
        }

        $execution = AgentExecution::query()->findOrFail((int) $input['source_execution_id']);
        $provenance = [
            'source_type' => AgentExecution::class,
            'source_id' => (int) $execution->getKey(),
            'source_step' => $input['source_step'] ?? null,
            'reason' => $input['reason'] ?? 'explicit_memory_update',
        ];

        return $this->serialize($this->memory->updateSemantic(
            $actor,
            $memory,
            (string) $input['statement'],
            (float) $input['confidence'],
            $provenance,
        ));
    }

    /** @return array<string, mixed> */
    public function archive(User $actor, Enterprise $enterprise, int $memoryId): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);
        $memory = AgentSemanticMemory::query()->findOrFail($memoryId);

        if ((int) $memory->enterprise_id !== (int) $enterprise->getKey()) {
            throw new InvalidArgumentException('Memory does not belong to the requested Enterprise.');
        }

        return $this->serialize($this->semantic->archive($actor, $memory));
    }

    /** @return array{episodic: list<array<string, mixed>>, semantic: list<array<string, mixed>>} */
    public function retrieve(
        User $actor,
        Enterprise $enterprise,
        AgentDescriptor $agent,
        ?string $topic = null,
        ?Carbon $relevantAfter = null,
        int $episodicLimit = 25,
        ?string $semanticStatus = null,
        int $semanticLimit = 50,
    ): array {
        $memory = $this->memory->retrieve(
            $actor,
            $enterprise,
            $agent,
            $topic,
            $relevantAfter,
            $episodicLimit,
            $semanticStatus,
            $semanticLimit,
        );

        return [
            'episodic' => array_values($memory['episodic']->map(fn (AgentEpisodicMemory $item): array => $this->serialize($item))->all()),
            'semantic' => array_values($memory['semantic']->map(fn (AgentSemanticMemory $item): array => $this->serialize($item))->all()),
        ];
    }

    public function find(Enterprise $enterprise, string $type, int $id): AgentEpisodicMemory|AgentSemanticMemory
    {
        $model = match ($type) {
            AgentMemoryPolicy::TYPE_EPISODIC => AgentEpisodicMemory::query()->findOrFail($id),
            AgentMemoryPolicy::TYPE_SEMANTIC => AgentSemanticMemory::query()->findOrFail($id),
            default => throw new InvalidArgumentException('Memory type must be episodic or semantic.'),
        };

        if ((int) $model->enterprise_id !== (int) $enterprise->getKey()) {
            throw new InvalidArgumentException('Memory does not belong to the requested Enterprise.');
        }

        return $model;
    }

    /** @return array<string, mixed> */
    public function serialize(AgentEpisodicMemory|AgentSemanticMemory $memory): array
    {
        $type = $memory instanceof AgentEpisodicMemory ? AgentMemoryPolicy::TYPE_EPISODIC : AgentMemoryPolicy::TYPE_SEMANTIC;

        return [
            'id' => $memory->getKey(),
            'type' => $type,
            'organization_id' => $memory->organization_id,
            'enterprise_id' => $memory->enterprise_id,
            'agent_descriptor_id' => $memory->agent_descriptor_id,
            'statement' => $memory instanceof AgentSemanticMemory ? $memory->statement : null,
            'confidence' => $memory instanceof AgentSemanticMemory ? $memory->confidence : null,
            'status' => $memory instanceof AgentSemanticMemory ? $memory->status : null,
            'topic' => $memory instanceof AgentEpisodicMemory ? $memory->topic : null,
            'objective' => $memory instanceof AgentEpisodicMemory ? $memory->objective : null,
            'action' => $memory instanceof AgentEpisodicMemory ? $memory->action : null,
            'result' => $memory instanceof AgentEpisodicMemory ? $memory->result : null,
            'outcome' => $memory instanceof AgentEpisodicMemory ? $memory->outcome : null,
            'occurred_at' => $memory instanceof AgentEpisodicMemory ? \Carbon\CarbonImmutable::parse((string) $memory->occurred_at)->toISOString() : null,
            'provenance' => $memory->provenanceMetadata(),
            'created_at' => \Carbon\CarbonImmutable::parse((string) $memory->created_at)->toISOString(),
            'updated_at' => \Carbon\CarbonImmutable::parse((string) $memory->updated_at)->toISOString(),
        ];
    }

    private function assertAgentScope(AgentDescriptor $agent, Enterprise $enterprise, AgentExecution $execution): void
    {
        if (
            ! $agent->enabled
            || (int) $execution->enterprise_id !== (int) $enterprise->getKey()
            || (int) $execution->organization_id !== (int) $enterprise->organization_id
            || (int) $execution->agent_descriptor_id !== (int) $agent->getKey()
        ) {
            throw new InvalidArgumentException('Memory Agent scope does not match the source execution and Enterprise.');
        }
    }
}