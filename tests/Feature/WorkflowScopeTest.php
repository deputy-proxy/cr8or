<?php

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'business-analysis',
    ], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

function scopeActorForEnterprises(Enterprise ...$enterprises): User
{
    $actor = User::factory()->create();

    foreach ($enterprises as $enterprise) {
        Membership::factory()->create([
            'user_id' => $actor,
            'organization_id' => $enterprise->organization_id,
            'role' => MembershipRole::Owner,
        ]);
    }

    return $actor;
}

function scopeStage(Workflow $workflow): WorkflowStage
{
    return WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => 'analysis',
        'name' => 'Analysis',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'input_contract' => ['required' => ['request']],
        'output_contract' => ['required' => ['analysis']],
    ]);
}

it('enforces the generic and enterprise-specific scope invariant', function (): void {
    $enterprise = Enterprise::factory()->create();

    $generic = Workflow::factory()->generic()->create();
    expect($generic->enterprise_id)->toBeNull()
        ->and($generic->enterprise_specific)->toBeFalse();

    expect(fn () => Workflow::factory()->create([
        'enterprise_specific' => true,
        'enterprise_id' => null,
    ]))->toThrow(LogicException::class);

    expect(fn () => Workflow::factory()->create([
        'enterprise_specific' => false,
        'enterprise_id' => $enterprise,
    ]))->toThrow(LogicException::class);
});

it('resolves a generic canonical workflow for every enterprise and prefers an enterprise-specific override', function (): void {
    $enterpriseA = Enterprise::factory()->create();
    $enterpriseB = Enterprise::factory()->create();

    $generic = Workflow::factory()->generic()->create([
        'canonical_key' => 'marketing.strategy.create',
    ]);

    expect(Workflow::query()
        ->forCanonicalKey($enterpriseA, 'marketing.strategy.create')
        ->firstOrFail()
        ->is($generic))->toBeTrue();

    $specific = Workflow::factory()->create([
        'enterprise_id' => $enterpriseA,
        'canonical_key' => 'marketing.strategy.create',
    ]);

    expect(Workflow::query()
        ->forCanonicalKey($enterpriseA, 'marketing.strategy.create')
        ->firstOrFail()
        ->is($specific))->toBeTrue();

    expect(Workflow::query()
        ->forCanonicalKey($enterpriseB, 'marketing.strategy.create')
        ->firstOrFail()
        ->is($generic))->toBeTrue();
});

it('executes the same generic published workflow for multiple enterprises', function (): void {
    $enterpriseA = Enterprise::factory()->create();
    $enterpriseB = Enterprise::factory()->create();
    $actor = scopeActorForEnterprises($enterpriseA, $enterpriseB);

    $workflow = Workflow::factory()->generic()->create([
        'name' => 'Generic Business Analysis',
        'canonical_key' => 'generic.business.analysis',
    ]);
    scopeStage($workflow);

    $version = app(WorkflowVersionService::class)->publish(
        $workflow,
        $actor,
        'generic-workflow-publish',
    );

    $executionA = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['request' => 'Analyze Enterprise A.'],
        'generic-workflow-execution-a',
        'generic-workflow-correlation-a',
        false,
        $enterpriseA,
    );

    $executionB = app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['request' => 'Analyze Enterprise B.'],
        'generic-workflow-execution-b',
        'generic-workflow-correlation-b',
        false,
        $enterpriseB,
    );

    expect($executionA->status)->toBe('completed')
        ->and($executionB->status)->toBe('completed')
        ->and($executionA->workflow_id)->toBe($workflow->getKey())
        ->and($executionB->workflow_id)->toBe($workflow->getKey())
        ->and($executionA->enterprise_id)->toBe($enterpriseA->getKey())
        ->and($executionB->enterprise_id)->toBe($enterpriseB->getKey())
        ->and($executionA->workflow_version_id)->toBe($version->getKey())
        ->and($executionB->workflow_version_id)->toBe($version->getKey());
});

it('rejects execution of an enterprise-specific workflow for another enterprise', function (): void {
    $enterpriseA = Enterprise::factory()->create();
    $enterpriseB = Enterprise::factory()->create();
    $actor = scopeActorForEnterprises($enterpriseA, $enterpriseB);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterpriseA,
        'canonical_key' => 'enterprise.specific.workflow',
    ]);
    scopeStage($workflow);

    $version = app(WorkflowVersionService::class)->publish(
        $workflow,
        $actor,
        'enterprise-specific-publish',
    );

    expect(fn () => app(WorkflowExecutionService::class)->start(
        $actor,
        $version,
        ['request' => 'This must not run for Enterprise B.'],
        'enterprise-specific-wrong-enterprise',
        'enterprise-specific-wrong-enterprise-correlation',
        false,
        $enterpriseB,
    ))->toThrow(AuthorizationException::class);
});

it('duplicates generic workflows without assigning them to an enterprise', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = scopeActorForEnterprises($enterprise);

    $workflow = Workflow::factory()->generic()->create([
        'name' => 'Generic Workflow',
        'canonical_key' => 'generic.workflow',
    ]);
    scopeStage($workflow);

    $duplicate = app(WorkflowEntryPointService::class)->duplicate($actor, $workflow);

    expect($duplicate->enterprise_specific)->toBeFalse()
        ->and($duplicate->enterprise_id)->toBeNull()
        ->and($duplicate->name)->toBe('Generic Workflow (Copy)')
        ->and($duplicate->canonical_key)->toBe('generic.workflow.copy')
        ->and($duplicate->stages()->count())->toBe(1);
});