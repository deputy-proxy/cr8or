<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\KnowledgeContextAssembler;
use Throwable;

final class KnowledgeContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly KnowledgeContextAssembler $assembler,
        private readonly RetrievedKnowledgeContextProvider $retrievedKnowledge,
    ) {}

    public function requirements(): array
    {
        return ['knowledge'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        $sections = [new AgentContextSection(
            name: 'knowledge',
            data: $this->assembler->assemble($user, $enterprise),
            source: self::class,
            scope: $this->scope($enterprise),
            relevance: 'Agent-declared Knowledge context requirement',
        )];

        if (array_key_exists('retrieved_knowledge', $targetContext)) {
            try {
                foreach ($this->retrievedKnowledge->provide($user, $enterprise, $targetContext, $assignment) as $section) {
                    $sections[] = $section;
                }
            } catch (Throwable) {
                $sections[] = new AgentContextSection(
                    name: 'retrieved_knowledge',
                    data: [
                        'query' => $targetContext['retrieved_knowledge']['query'] ?? null,
                        'objective' => $targetContext['retrieved_knowledge']['objective'] ?? null,
                        'items' => [],
                        'selection' => [
                            'mode' => $targetContext['retrieved_knowledge']['mode'] ?? 'hybrid',
                            'requested_limit' => $targetContext['retrieved_knowledge']['limit'] ?? null,
                            'returned_count' => 0,
                            'candidate_count' => 0,
                            'budget' => $targetContext['retrieved_knowledge']['budget'] ?? null,
                            'estimated_tokens' => 0,
                            'truncated' => false,
                        ],
                        'sufficiency' => [
                            'status' => 'unavailable',
                            'reason' => 'retrieval_failed',
                        ],
                    ],
                    source: self::class,
                    scope: $this->scope($enterprise),
                    relevance: 'Knowledge retrieval failed safely; authoritative Knowledge context remains available',
                );
            }
        }

        return $sections;
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