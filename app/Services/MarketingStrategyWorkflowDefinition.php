<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\Workflow;

final class MarketingStrategyWorkflowDefinition
{
    public const TEMPLATE = 'marketing.full-strategy';

    public function create(Enterprise $enterprise, string $prompt, ?string $template = null): Workflow
    {
        if ($template !== null && $template !== self::TEMPLATE) {
            throw new \InvalidArgumentException("Unknown workflow template [{$template}].");
        }

        $workflow = Workflow::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Autonomous marketing strategy',
            'purpose' => $prompt,
            'version' => 1,
            'execution_policy' => [
                'template' => self::TEMPLATE,
                'max_steps' => 12,
                'no_publication' => true,
                'no_media_generation' => true,
            ],
            'completion_criteria' => [
                'required_stage_keys' => [
                    'enterprise-analysis',
                    'audiences',
                    'strategy',
                    'campaigns',
                    'content-series',
                    'content-items',
                    'scripts',
                    'assets',
                    'verification',
                ],
            ],
            'status' => Workflow::STATUS_PENDING,
        ]);

        $stages = [
            [
                'key' => 'enterprise-analysis', 'name' => 'Analyze enterprise context', 'sequence' => 1,
                'expert_slugs' => ['marketing', 'strategy'], 'capability_slugs' => ['marketing.plan'],
                'output_contract' => ['required' => ['marketing_plan']],
            ],
            [
                'key' => 'audiences', 'name' => 'Build audiences', 'sequence' => 2, 'dependencies' => ['enterprise-analysis'],
                'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.audience.create'],
            ],
            [
                'key' => 'strategy', 'name' => 'Create marketing strategy', 'sequence' => 3, 'dependencies' => ['audiences'],
                'expert_slugs' => ['marketing', 'strategy'], 'capability_slugs' => ['marketing.strategy.create'],
            ],
            [
                'key' => 'campaigns', 'name' => 'Create campaigns', 'sequence' => 4, 'dependencies' => ['strategy'],
                'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.campaign.create'],
            ],
            [
                'key' => 'content-series', 'name' => 'Create content series', 'sequence' => 5, 'dependencies' => ['campaigns'],
                'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.content-series.create'],
            ],
            [
                'key' => 'content-items', 'name' => 'Create content items', 'sequence' => 6, 'dependencies' => ['content-series'],
                'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.content.create'],
            ],
            [
                'key' => 'scripts', 'name' => 'Create scripts and asset requirements', 'sequence' => 7, 'dependencies' => ['content-items'],
                'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.script.create'],
            ],
            [
                'key' => 'assets', 'name' => 'Persist required assets as pending', 'sequence' => 8, 'dependencies' => ['scripts'],
                'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.asset.create'],
            ],
            [
                'key' => 'verification', 'name' => 'Verify the resulting marketing graph', 'sequence' => 9, 'dependencies' => ['assets'],
                'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.graph.verify'],
                'completion_criteria' => ['requires_termination_completed' => true, 'required_capability_results' => [
                    ['capability' => 'marketing.graph.verify', 'result_key' => 'verification_passed', 'result_value' => true],
                ]],
            ],
        ];

        foreach ($stages as $stage) {
            $workflow->stages()->create(array_merge([
                'dependencies' => [],
                'input_contract' => [],
                'output_contract' => [],
                'capability_slugs' => [],
                'expert_slugs' => [],
                'repeatable' => false,
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                    'required_capability_results' => $stage['capability_slugs'],
                ],
            ], $stage));
        }

        return $workflow->load('stages');
    }
}
