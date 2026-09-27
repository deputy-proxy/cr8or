<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeContextAssembler;

final class KnowledgeContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly KnowledgeContextAssembler $assembler,
    ) {}

    public function requirements(): array
    {
        return ['knowledge'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        return [new AgentContextSection(
            name: 'knowledge',
            data: $this->assembler->assemble($user, $enterprise),
            source: self::class,
            scope: $this->scope($enterprise),
            relevance: 'Agent-declared context requirement',
        )];
    }

    /** @return array{organization_id: int|string, enterprise_id: int|string} */
    private function scope(Enterprise $enterprise): array
    {
        return [
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
        ];
    }
}