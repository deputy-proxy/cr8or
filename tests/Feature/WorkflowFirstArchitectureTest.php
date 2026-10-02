<?php

use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use App\Services\MarketingStrategyWorkflowDefinition;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowVersionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
    Queue::fake();
});

it('proves the canonical deterministic Workflow boundary without Agent or ModelProvider execution', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $workflow = app(MarketingStrategyWorkflowDefinition::class)->createCanonical($enterprise);
    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'workflow-first-proof');

    $execution = app(WorkflowEntryPointService::class)->start(
        $actor,
        $workflow->refresh(),
        ['strategy_name' => 'Workflow-first proof strategy'],
        'workflow-first-proof-execution',
    );

    expect($version->status)->toBe(WorkflowVersion::STATUS_PUBLISHED)
        ->and($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->workflow_version_id)->toBe($version->getKey())
        ->and($execution->outputs)->toHaveKeys([
            'enterprise_context',
            'business_market_context',
            'target_audiences',
            'positioning',
            'persist_strategy',
        ])
        ->and(AgentExecution::query()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();

    $duplicate = app(WorkflowEntryPointService::class)->start(
        $actor,
        $workflow->refresh(),
        ['strategy_name' => 'Workflow-first proof strategy'],
        'workflow-first-proof-execution',
    );

    expect($duplicate->getKey())->toBe($execution->getKey())
        ->and($duplicate->workflow_version_id)->toBe($version->getKey());

    expect(fn () => app(WorkflowEntryPointService::class)->resume(
        $actor,
        $execution->refresh(),
        'stale-token',
    ))->toThrow(AuthorizationException::class, 'stale or invalid');
});
