<?php

use App\Models\Enterprise;
use App\Services\MarketingStrategyWorkflowDefinition;

it('defines exactly 18 stable canonical marketing stages with governed contracts', function (): void {
    $stages = app(MarketingStrategyWorkflowDefinition::class)->canonicalStages();

    expect($stages)->toHaveCount(18)
        ->and(array_column($stages, 'sequence'))->toBe(range(1, 18))
        ->and(array_column($stages, 'key'))->toEqualCanonicalizing([
            'enterprise_context','business_market_context','target_audiences','positioning',
            'value_proposition','competitive_landscape','product_service_strategy','marketing_objectives',
            'acquisition_channels','content_strategy','seo_strategy','social_strategy','conversion_strategy',
            'retention_strategy','measurement_kpis','roadmap_90_days','completeness_validation','persist_strategy',
        ]);

    foreach ($stages as $stage) {
        expect($stage['expert_slugs'])->not->toBeEmpty()
            ->and($stage['capability_slugs'])->not->toBeEmpty()
            ->and($stage['input_contract'])->toBeArray()
            ->and($stage['output_contract'])->toBeArray();
    }
});

it('creates the same canonical stage graph on repeated construction', function (): void {
    $definition = app(MarketingStrategyWorkflowDefinition::class);
    $first = $definition->canonicalStages();
    $second = $definition->canonicalStages();

    expect($second)->toBe($first);
});
