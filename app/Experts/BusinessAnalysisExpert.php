<?php

namespace App\Experts;

final class BusinessAnalysisExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Business Analysis',
            description: 'Analyzes enterprise context, priorities, work and financial signals without executing business operations.',
            responsibilities: ['analyze enterprise context', 'identify dependencies', 'surface decision inputs'],
            methodology: 'Evidence-first analysis of authorized enterprise context.',
            requiredContext: ['enterprise', 'strategy', 'work', 'financial'],
            capabilities: ['business.analysis'],
        );
    }

    public function analyze(array $context): array
    {
        return $this->structuredReasoning(
            $context,
            'business analysis',
            'Analyze authorized enterprise context, dependencies, and decision inputs.',
            [['action' => 'surface decision inputs', 'rationale' => 'Use only authorized enterprise evidence.']],
        );
    }
}