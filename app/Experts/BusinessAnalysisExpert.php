<?php

namespace App\Experts;

final class BusinessAnalysisExpert extends Expert
{
    public function name(): string
    {
        return 'Business Analysis';
    }

    public function description(): string
    {
        return 'Analyzes enterprise context, priorities, work and financial signals without executing business operations.';
    }

    public function responsibilities(): array
    {
        return ['analyze enterprise context', 'identify dependencies', 'surface decision inputs'];
    }

    public function capabilities(): array
    {
        return ['business.analysis'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'strategy', 'work', 'financial'];
    }

    public function methodology(): string
    {
        return 'Evidence-first analysis of authorized enterprise context.';
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'business analysis',
            'available_context' => array_keys($context),
        ];
    }
}
