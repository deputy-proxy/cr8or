<?php

namespace App\Experts;

final class SeoExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'SEO',
            description: 'Applies search optimization methodology to authorized enterprise and knowledge context without publishing content.',
            responsibilities: [
                'analyze search intent',
                'identify discoverability opportunities',
                'review content for search considerations',
            ],
            methodology: 'Evidence-first search-intent and discoverability analysis.',
            requiredContext: ['enterprise', 'knowledge'],
            capabilities: ['marketing.content.review'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'seo',
            'available_context' => array_keys($context),
        ];
    }
}