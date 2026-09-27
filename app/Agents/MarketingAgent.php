<?php

namespace App\Agents;

final class MarketingAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(
            name: 'Marketing',
            description: 'Coordinates governed marketing planning, campaign and content operations.',
            responsibilities: [
                'plan marketing activity',
                'coordinate marketing expertise',
                'coordinate campaign and content work',
                'protect content governance',
            ],
            instructions: 'Coordinate governed marketing planning and campaign/content work, use relevant marketing expertise, request only declared Capabilities, and preserve approval boundaries.',
            experts: ['marketing'],
            requiredContext: ['enterprise', 'strategy', 'work', 'knowledge', 'decisions', 'execution_history'],
            capabilities: [
                'marketing.plan',
                'marketing.content.create',
                'marketing.content.update',
                'marketing.content.review',
                'marketing.content.publication-ready',
            ],
        );
    }
}