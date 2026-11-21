<?php

namespace App\Experts;

final class KnowledgeExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Knowledge',
            description: 'Manages Enterprise Knowledge sources, documents, contexts, specifications, references, authoritative items, and their governed retrieval and indexing lifecycle.',
            responsibilities: [
                'manage Enterprise-scoped Knowledge Sources, Documents, Contexts, Specifications, and References',
                'inspect and curate authoritative Knowledge Items',
                'maintain versioned Knowledge content, formal specifications, and evidence references',
                'retrieve and maintain derived Knowledge representations',
            ],
            methodology: 'Preserve Enterprise boundaries and provenance; maintain authoritative Knowledge catalog records and item history; treat indexes and units as derived representations.',
            requiredContext: ['enterprise', 'knowledge'],
            capabilities: [
                'knowledge.source.create',
                'knowledge.source.get',
                'knowledge.source.list',
                'knowledge.source.update',
                'knowledge.document.create',
                'knowledge.document.get',
                'knowledge.document.list',
                'knowledge.document.update',
                'knowledge.context.create',
                'knowledge.context.get',
                'knowledge.context.list',
                'knowledge.context.update',
                'knowledge.specification.create',
                'knowledge.specification.get',
                'knowledge.specification.list',
                'knowledge.specification.update',
                'knowledge.reference.create',
                'knowledge.reference.get',
                'knowledge.reference.list',
                'knowledge.reference.update',
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
