<?php

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use App\Models\WorkflowExecution;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowScopeService;
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

it('allows an existing workflow to change scope', function (): void {
    $enterpriseA = Enterprise::factory()->create();
    $enterpriseB = Enterprise::factory()->create();
    $actor = scopeActorForEnterprises($enterpriseA, $enterpriseB);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterpriseA->getKey(),
        'enterprise_specific' => true,
    ]);

    app(WorkflowScopeService::class)->change($actor, $workflow, false);

    expect($workflow->refresh()->enterprise_specific)->toBeFalse()
        ->and($workflow->enterprise_id)->toBeNull();

    app(WorkflowScopeService::class)->change($actor, $workflow, true, $enterpriseB);

    expect($workflow->refresh()->enterprise_specific)->toBeTrue()
        ->and($workflow->enterprise_id)->toBe($enterpriseB->getKey());
});

it('creates a new published version when changing the scope of an existing published workflow', function (): void {
    $enterpriseA = Enterprise::factory()->create();
    $actor = scopeActorForEnterprises($enterpriseA);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterpriseA->getKey(),
        'enterprise_specific' => true,
        'canonical_key' => 'scope.migration',
    ]);
    scopeStage($workflow);

    $published = app(WorkflowVersionService::class)->publish(
        $workflow,
        $actor,
        'scope-migration-publish',
    );

    $changed = app(WorkflowScopeService::class)->change($actor, $workflow, false);

    $changed->load('publishedVersion');

    expect($changed->enterprise_specific)->toBeFalse()
        ->and($changed->enterprise_id)->toBeNull()
        ->and($changed->publishedVersion?->enterprise_id)->toBeNull()
        ->and($changed->publishedVersion?->version)->toBe($published->version + 1)
        ->and($published->refresh()->status)->toBe(WorkflowVersion::STATUS_RETIRED);
});

it('rejects scope changes while a workflow execution is active', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = scopeActorForEnterprises($enterprise);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'enterprise_specific' => true,
    ]);
    scopeStage($workflow);

    $version = app(WorkflowVersionService::class)->publish(
        $workflow,
        $actor,
        'active-scope-publish',
    );

    WorkflowExecution::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'workflow_version_id' => $version->getKey(),
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise->getKey(),
        'actor_id' => $actor->getKey(),
        'status' => WorkflowExecution::STATUS_WAITING_FOR_APPROVAL,
    ]);

    expect(fn () => app(WorkflowScopeService::class)->change($actor, $workflow, false))
        ->toThrow(LogicException::class, 'Workflow scope cannot be changed while an execution is active');
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