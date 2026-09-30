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
            capabilities: ['marketing.content.create', 'marketing.content.update', 'marketing.content.review', 'marketing.script.create'],
        );
    }

    public function analyze(array $context): array
    {
        $capabilityRequests = [];

        if (isset($context['enterprise']['enterprise']['id'])) {
            $capabilityRequests[] = [
                'capability' => 'marketing.content.create',
                'target_context' => [
                    'enterprise_id' => $context['enterprise']['enterprise']['id'],
                ],
                'input_payload' => [
                    'source' => 'copywriting-expert',
                ],
            ];
        }

        return $this->structuredReasoning(
            $context,
            'copywriting',
            'Analyze messaging clarity and recommend content language improvements without publishing content.',
            [
                [
                    'action' => 'develop messaging recommendations',
                    'rationale' => 'Use authorized knowledge and enterprise context.',
                ],
            ],
            $capabilityRequests,
        );
    }
}