<?php

namespace App\Experts;

final class MarketingExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Marketing',
            description: 'Applies marketing planning methodology to authorized enterprise and strategic context.',
            responsibilities: ['analyze audience and positioning', 'identify campaign opportunities', 'support content planning'],
            methodology: 'Audience-first, strategy-aligned marketing analysis.',
            requiredContext: ['enterprise', 'strategy', 'knowledge'],
            capabilities: [
                'marketing.plan',
                'marketing.strategy.section.define',
                'marketing.strategy.create',
                'marketing.audience.create',
                'marketing.campaign.create',
                'marketing.competitor.create',
                'marketing.competitor.report.generate',
                'marketing.content-series.create',
                'marketing.content.review',
                'marketing.graph.verify',
                'marketing.content.approve',
                'marketing.content.publication-ready',
                'publication.publish',
                'publication.status.reconcile',
                'marketing.social-account.update',
                'marketing.social-account.disable',
            ],
        );
    }

    public function analyze(array $context): array
    {
        return $this->structuredReasoning(
            $context,
            'marketing planning',
            'Analyze audience, positioning, campaign opportunities, and content planning within authorized context.',
            [
                [
                    'action' => 'align marketing activity',
                    'rationale' => 'Use authorized strategy and knowledge evidence.',
                ],
            ],
        );
    }
}