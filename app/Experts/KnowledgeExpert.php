<?php

namespace App\Experts;

final class KnowledgeExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Knowledge',
            description: 'Manages authoritative Enterprise Knowledge Items and their governed retrieval and indexing lifecycle.',
            responsibilities: [
                'inspect and curate authoritative Knowledge Items',
                'maintain versioned Knowledge content and provenance',
                'retrieve and maintain derived Knowledge representations',
            ],
            methodology: 'Preserve authoritative Knowledge history, Enterprise boundaries and provenance; treat indexes and units as derived representations.',
            requiredContext: ['enterprise', 'knowledge'],
            capabilities: [
                'knowledge.item.create',
                'knowledge.item.get',
                'knowledge.item.list',
                'knowledge.item.update',
                'knowledge.index.create',
                'knowledge.index.get',
                'knowledge.index.list',
                'knowledge.index.update',
                'knowledge.unit.create',
                'knowledge.unit.get',
                'knowledge.unit.list',
                'knowledge.unit.update',
                'knowledge.unit.archive',
                'knowledge.retrieve',
            ],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'knowledge management',
            'available_context' => array_keys($context),
        ];
    }
}
