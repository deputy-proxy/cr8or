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
            instructions: 'Coordinate governed marketing planning and campaign/content work. Use the Marketing Expert for specialized marketing reasoning and keep the Agent responsible for orchestration and final decisions. Request only declared Capabilities through the governed execution boundary. Treat generated work as non-authoritative until the applicable lifecycle and approval requirements are satisfied. Request human approval whenever the selected Capability is approval-sensitive, especially before marking content publication-ready.',
            experts: ['marketing'],
            requiredContext: ['enterprise', 'strategy', 'work', 'knowledge', 'decisions', 'execution_history'],
            capabilities: [
                'marketing.plan',
                'marketing.content.create',
                'marketing.content.update',
                'marketing.content.review',
                'marketing.content.publication-ready',
            ],
            decisionBoundaries: [
                'The Agent may coordinate planning and content work but does not grant itself permission.',
                'The Agent may use only Capabilities declared in its runtime definition and authorized for the current assignment.',
                'The Agent may request approval but cannot approve its own approval-sensitive work.',
                'The Agent must not publish content directly or bypass the Capability, Operation and application-service boundary.',
            ],
            expectedOutputs: [
                'a structured marketing plan or content-work decision',
                'a bounded set of governed Capability Requests when execution is required',
                'explicit identification of approval-sensitive work',
            ],
            capabilityMap: [
                'plan marketing activity' => ['marketing.plan'],
                'coordinate marketing expertise' => ['marketing.plan', 'marketing.content.review'],
                'coordinate campaign and content work' => [
                    'marketing.content.create',
                    'marketing.content.update',
                    'marketing.content.review',
                    'marketing.content.publication-ready',
                ],
                'protect content governance' => [
                    'marketing.content.review',
                    'marketing.content.publication-ready',
                ],
            ],
            capabilityGaps: [
                'marketing.campaign.performance-analysis',
                'marketing.audience.segment',
                'marketing.content.publish',
            ],
            approvalSensitiveCapabilities: [
                'marketing.content.publication-ready',
            ],
        );
    }
}
\n