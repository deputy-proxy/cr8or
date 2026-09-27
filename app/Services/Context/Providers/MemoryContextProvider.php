<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentMemoryService;
use Illuminate\Auth\Access\AuthorizationException;

final class MemoryContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly AgentMemoryService $memory,
    ) {}

    public function requirements(): array
    {
        return ['memory'];
    }

    public function provide(
        User $user,
        Enterprise $enterprise,
        array $targetContext = [],
        ?AgentAssignment $assignment = null,
    ): array {
        if (! $assignment instanceof AgentAssignment) {
            throw new AuthorizationException(
                'Agent memory context requires the current Agent Enterprise assignment.',
            );
        }

        $assignment->loadMissing('agentDescriptor');

        if (
            ! $assignment->enabled
            || $assignment->enterprise_id !== $enterprise->getKey()
            || $assignment->organization_id !== $enterprise->organization_id
            || ! $assignment->agentDescriptor->enabled
        ) {
            throw new AuthorizationException(
                'Agent memory context requires the current enabled Agent Enterprise assignment.',
            );
        }

        $memory = $this->memory->retrieve(
            actor: $user,
            enterprise: $enterprise,
            agent: $assignment->agentDescriptor,
        );

        return [new AgentContextSection(
            name: 'memory',
            data: [
                'episodic' => $memory['episodic']->map(
                    static function ($item): array {
                        $data = $item->toArray();
                        $data['provenance'] = $item->provenanceMetadata();

                        return $data;
                    },
                )->all(),
                'semantic' => $memory['semantic']->map(
                    static function ($item): array {
                        $data = $item->toArray();
                        $data['provenance'] = $item->provenanceMetadata();

                        return $data;
                    },
                )->all(),
            ],
            source: self::class,
            scope: [
                'organization_id' => $enterprise->organization_id,
                'enterprise_id' => $enterprise->getKey(),
                'agent_assignment_id' => $assignment->getKey(),
                'agent_descriptor_id' => $assignment->agent_descriptor_id,
            ],
            relevance: 'Agent-declared persistent memory requirement',
        )];
    }
}