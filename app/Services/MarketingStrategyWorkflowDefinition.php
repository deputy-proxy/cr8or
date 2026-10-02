<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\Workflow;

final class MarketingStrategyWorkflowDefinition
{
    public const TEMPLATE = 'marketing.full-strategy';

    public const CANONICAL_TEMPLATE = 'marketing.strategy.create';

    /** @return list<array<string, mixed>> */
    public function canonicalStages(): array
    {
        return [
            [
                'key' => 'enterprise_context',
                'name' => 'Load canonical Enterprise context',
                'sequence' => 1,
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'enterprise_context']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'business_market_context',
                'name' => 'Analyze business and market context',
                'sequence' => 2,
                'dependencies' => ['enterprise_context'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.plan'],
                'input_contract' => ['required' => []],
                'output_contract' => ['required' => ['analysis']],
            ],
            [
                'key' => 'target_audiences',
                'name' => 'Define target audiences and customer segments',
                'sequence' => 3,
                'dependencies' => ['business_market_context'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.audience.create'],
                'input_contract' => [
                    'required' => ['name', 'description'],
                    'defaults' => [
                        'name' => 'Primary target audience',
                        'description' => 'Primary audience defined from the authorized enterprise context.',
                    ],
                ],
                'output_contract' => ['required' => ['id']],
            ],
            [
                'key' => 'positioning',
                'name' => 'Define positioning',
                'sequence' => 4,
                'dependencies' => ['target_audiences'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'positioning']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'value_proposition',
                'name' => 'Define value proposition',
                'sequence' => 5,
                'dependencies' => ['positioning'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'value_proposition']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'competitive_landscape',
                'name' => 'Analyze competitive landscape',
                'sequence' => 6,
                'dependencies' => ['value_proposition'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'competitive_landscape']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'product_service_strategy',
                'name' => 'Define product/service strategy',
                'sequence' => 7,
                'dependencies' => ['competitive_landscape'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'product_service_strategy']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'marketing_objectives',
                'name' => 'Define marketing objectives',
                'sequence' => 8,
                'dependencies' => ['product_service_strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'marketing_objectives']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'acquisition_channels',
                'name' => 'Define acquisition channels',
                'sequence' => 9,
                'dependencies' => ['marketing_objectives'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'acquisition_channels']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'content_strategy',
                'name' => 'Define content strategy',
                'sequence' => 10,
                'dependencies' => ['acquisition_channels'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'content_strategy']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'seo_strategy',
                'name' => 'Define SEO strategy',
                'sequence' => 11,
                'dependencies' => ['content_strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'seo_strategy']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'social_strategy',
                'name' => 'Define social strategy',
                'sequence' => 12,
                'dependencies' => ['seo_strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'social_strategy']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'conversion_strategy',
                'name' => 'Define conversion strategy',
                'sequence' => 13,
                'dependencies' => ['social_strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'conversion_strategy']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'retention_strategy',
                'name' => 'Define retention strategy',
                'sequence' => 14,
                'dependencies' => ['conversion_strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'retention_strategy']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'measurement_kpis',
                'name' => 'Define measurement and KPIs',
                'sequence' => 15,
                'dependencies' => ['retention_strategy'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'measurement_kpis']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'roadmap_90_days',
                'name' => 'Build the 90-day execution roadmap',
                'sequence' => 16,
                'dependencies' => ['measurement_kpis'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'roadmap_90_days']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'completeness_validation',
                'name' => 'Validate completeness and consistency',
                'sequence' => 17,
                'dependencies' => ['roadmap_90_days'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.section.define'],
                'input_contract' => ['required' => ['section_key'], 'defaults' => ['section_key' => 'completeness_validation']],
                'output_contract' => ['required' => ['key', 'data']],
            ],
            [
                'key' => 'persist_strategy',
                'name' => 'Persist the new Marketing Strategy',
                'sequence' => 18,
                'dependencies' => ['completeness_validation'],
                'expert_slugs' => ['marketing'],
                'capability_slugs' => ['marketing.strategy.create'],
                'input_contract' => [
                    'required' => ['name', 'sections'],
                    'defaults' => [
                        'name' => 'Canonical Marketing Strategy',
                        'description' => 'Deterministic marketing strategy created from authorized enterprise context.',
                        'new_only' => true,
                    ],
                    'mappings' => [
                        'name' => 'strategy_name',
                        'description' => 'strategy_description',
                        'sections' => 'stages',
                    ],
                ],
                'output_contract' => ['required' => ['id', 'name', 'sections']],
            ],
        ];
    }

    public function createCanonical(Enterprise $enterprise): Workflow
    {
        $workflow = Workflow::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Canonical Marketing Strategy Workflow',
            'purpose' => 'Create a new deterministic marketing strategy from canonical enterprise context.',
            'execution_policy' => [
                'template' => self::CANONICAL_TEMPLATE,
                'mode' => 'deterministic',
                'new_only' => true,
                'requires_model_provider' => false,
            ],
            'completion_criteria' => [
                'required_stage_keys' => array_column($this->canonicalStages(), 'key'),
            ],
            'status' => Workflow::STATUS_PENDING,
        ]);

        foreach ($this->canonicalStages() as $stage) {
            $workflow->stages()->create(array_merge([
                'dependencies' => [],
                'input_contract' => [],
                'output_contract' => [],
                'capability_slugs' => [],
                'expert_slugs' => [],
                'repeatable' => false,
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                ],
            ], $stage));
        }

        return $workflow->load('stages');
    }

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