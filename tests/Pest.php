<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function persistedMarketingAgentWorkflow(\App\Models\Enterprise $enterprise, \App\Models\User $actor): \App\Models\Workflow
{
    $stages = [
        ['key' => 'enterprise-analysis', 'name' => 'Analyze enterprise context', 'sequence' => 1, 'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.plan']],
        ['key' => 'audiences', 'name' => 'Build audiences', 'sequence' => 2, 'dependencies' => ['enterprise-analysis'], 'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.audience.create']],
        ['key' => 'strategy', 'name' => 'Create marketing strategy', 'sequence' => 3, 'dependencies' => ['audiences'], 'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.strategy.create']],
        ['key' => 'campaigns', 'name' => 'Create campaigns', 'sequence' => 4, 'dependencies' => ['strategy'], 'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.campaign.create']],
        ['key' => 'content-series', 'name' => 'Create content series', 'sequence' => 5, 'dependencies' => ['campaigns'], 'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.content-series.create']],
        ['key' => 'content-items', 'name' => 'Create content items', 'sequence' => 6, 'dependencies' => ['content-series'], 'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.content.create']],
        ['key' => 'scripts', 'name' => 'Create scripts and asset requirements', 'sequence' => 7, 'dependencies' => ['content-items'], 'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.script.create']],
        ['key' => 'assets', 'name' => 'Persist required assets as pending', 'sequence' => 8, 'dependencies' => ['scripts'], 'expert_slugs' => ['copywriting'], 'capability_slugs' => ['marketing.asset.create']],
        ['key' => 'verification', 'name' => 'Verify the resulting marketing graph', 'sequence' => 9, 'dependencies' => ['assets'], 'expert_slugs' => ['marketing'], 'capability_slugs' => ['marketing.graph.verify']],
    ];

    $workflow = app(\App\Services\WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Persisted Full Marketing Strategy Workflow',
        'canonical_key' => 'marketing.full-strategy',
        'purpose' => 'Test-only persisted workflow used to verify generic Agent orchestration.',
        'execution_policy' => ['mode' => 'agent', 'requires_model_provider' => true],
        'completion_criteria' => ['required_stage_keys' => array_column($stages, 'key')],
        'stages' => $stages,
    ]);

    app(\App\Services\WorkflowVersionService::class)->publish($workflow, $actor, 'test-persisted-marketing-workflow-'.$enterprise->getKey());

    return $workflow->refresh();
}