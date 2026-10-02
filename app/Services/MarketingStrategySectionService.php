<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;

final class MarketingStrategySectionService
{
    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function define(User $actor, Enterprise $enterprise, string $sectionKey, array $context = []): array
    {
        $enterpriseContext = $enterprise->context;
        $title = ucwords(str_replace('_', ' ', $sectionKey));

        $data = match ($sectionKey) {
            'enterprise_context' => [
                'enterprise' => $enterprise->only(['id', 'name', 'slug', 'status']),
                'context' => $enterpriseContext?->only([
                    'description', 'industry', 'business_model', 'target_market', 'geography', 'additional_context',
                ]) ?? [],
            ],
            'business_market_context' => [
                'enterprise' => $enterprise->only(['id', 'name', 'slug', 'status']),
                'context' => $enterpriseContext?->only([
                    'description', 'industry', 'business_model', 'target_market', 'geography', 'additional_context',
                ]) ?? [],
            ],
            'target_audiences' => [
                'audiences' => $enterprise->audiences()->orderBy('id')->get(['id', 'name', 'description', 'status'])->toArray(),
                'target_market' => $enterpriseContext?->target_market,
            ],
            'positioning' => [
                'industry' => $enterpriseContext?->industry,
                'target_market' => $enterpriseContext?->target_market,
                'positioning_basis' => 'Differentiate the enterprise around its documented capabilities, market and customer problem.',
            ],
            'value_proposition' => [
                'business_model' => $enterpriseContext?->business_model,
                'value_proposition_basis' => 'Connect the documented customer problem to the enterprise capability and measurable outcome.',
            ],
            'competitive_landscape' => [
                'competitors' => $enterprise->competitors()->orderBy('id')->get()->toArray(),
                'assumption' => 'No competitor is invented when authoritative competitor data is absent.',
            ],
            'product_service_strategy' => [
                'products' => $enterprise->products()->orderBy('id')->get()->toArray(),
                'strategy_basis' => 'Prioritize documented products and services before introducing unsupported offerings.',
            ],
            'marketing_objectives' => [
                'objectives' => $enterprise->objectives()->orderBy('id')->get()->toArray(),
                'goals' => $enterprise->goals()->orderBy('id')->get()->toArray(),
                'kpis' => $enterprise->kpis()->orderBy('id')->get()->toArray(),
            ],
            'acquisition_channels' => [
                'channels' => $enterprise->channels()->orderBy('id')->get()->toArray(),
                'channel_rule' => 'Use existing authorized channels; identify gaps as assumptions rather than silently inventing records.',
            ],
            'content_strategy' => [
                'principles' => ['strategy-aligned', 'audience-specific', 'evidence-grounded', 'measurable'],
            ],
            'seo_strategy' => [
                'principles' => [
                    'align search intent to documented audience problems',
                    'build topical authority from enterprise knowledge',
                    'measure qualified organic acquisition',
                ],
            ],
            'social_strategy' => [
                'principles' => [
                    'adapt content to channel behavior',
                    'use repeatable content pillars',
                    'measure meaningful engagement and conversion',
                ],
            ],
            'conversion_strategy' => [
                'principles' => [
                    'clear value proposition',
                    'single next action per journey step',
                    'measurable conversion events',
                ],
            ],
            'retention_strategy' => [
                'principles' => [
                    'deliver measurable customer value',
                    'capture customer feedback',
                    'identify expansion and renewal signals',
                ],
            ],
            'measurement_kpis' => [
                'kpis' => $enterprise->kpis()->orderBy('id')->get()->toArray(),
                'measurement_rule' => 'Prefer existing authoritative KPIs and add assumptions explicitly.',
            ],
            'roadmap_90_days' => [
                'days_1_30' => ['validate context', 'finalize positioning', 'establish measurement baseline'],
                'days_31_60' => ['activate priority channels', 'publish core content', 'measure acquisition'],
                'days_61_90' => ['optimize conversion', 'improve retention signals', 'review performance'],
            ],
            'completeness_validation' => [
                'required_sections' => [
                    'business_market_context', 'target_audiences', 'positioning', 'value_proposition',
                    'competitive_landscape', 'product_service_strategy', 'marketing_objectives',
                    'acquisition_channels', 'content_strategy', 'seo_strategy', 'social_strategy',
                    'conversion_strategy', 'retention_strategy', 'measurement_kpis', 'roadmap_90_days',
                ],
                'validation' => 'Every required section must be present and assumptions must remain explicit.',
            ],
            default => ['context_categories' => array_keys($context)],
        };

        return [
            'key' => $sectionKey,
            'title' => $title,
            'data' => $data,
            'source' => 'enterprise_context',
            'deterministic' => true,
        ];
    }
}