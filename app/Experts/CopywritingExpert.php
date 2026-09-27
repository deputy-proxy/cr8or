<?php

namespace App\Experts;

final class CopywritingExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Copywriting',
            description: 'Applies copywriting methodology to authorized knowledge and marketing context without publishing content.',
            responsibilities: [
                'develop messaging recommendations',
                'refine content language',
                'identify content clarity issues',
            ],
            methodology: 'Audience-aware messaging analysis grounded in authorized knowledge and marketing context.',
            requiredContext: ['enterprise', 'knowledge'],
            capabilities: ['marketing.content.create', 'marketing.content.update', 'marketing.content.review'],
        );
    }

    public function analyze(array $context): array
    {
        $result = [
            'focus' => 'copywriting',
            'available_context' => array_keys($context),
        ];

        if (isset($context['enterprise']['enterprise']['id'])) {
            $result['capability_requests'] = [[
                'capability' => 'marketing.content.create',
                'target_context' => [
                    'enterprise_id' => $context['enterprise']['enterprise']['id'],
                ],
                'input_payload' => [
                    'source' => 'copywriting-expert',
                ],
            ]];
        }

        return $result;
    }
}