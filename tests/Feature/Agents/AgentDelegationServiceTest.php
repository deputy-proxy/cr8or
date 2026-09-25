<?php

use App\AI\Contracts\ModelProvider;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentDelegationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentDelegationService;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    app()->bind(ModelProvider::class, fn (): FakeModelProvider => FakeModelProvider::returning());
});

function delegationSourceRuntimeClass(): string
{
    return get_class(new class extends \App\Agents\Agent
    {
        public function name(): string
        {
            return 'Source Delegation Agent';
        }

        public function description(): string
        {
            return 'Originates governed delegated enterprise work.';
        }

        public function responsibilities(): array
        {
            return ['delegate'];
        }

        public function capabilities(): array
        {
            return ['agent.delegate'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }
    });
}

function delegationTargetRuntimeClass(): string
{
    return get_class(new class extends \App\Agents\Agent
    {
        public function name(): string
        {
            return 'Target Delegation Agent';
        }

        public function description(): string
        {
            return 'Receives governed delegated enterprise work.';
        }

        public function responsibilities(): array
        {
            return ['execute'];
        }

        public function capabilities(): array
        {
            return ['work.item.create'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }
    });
}

function delegationCrossScopeRuntimeClass(): string
{
    return get_class(new class extends \App\Agents\Agent
    {
        public function name(): string
        {
            return 'Cross Scope Delegation Agent';
        }

        public function description(): string
        {
            return 'Used to verify delegation scope boundaries.';
        }

        public function responsibilities(): array
        {
            return ['execute'];
        }

        public function capabilities(): array
        {
            return ['work.item.create'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }
    });
}

it('binds an approval to one delegation and rejects rebinding it', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');

    $approval = app(\App\Services\ApprovalRequestService::class)->request(
        $actor,
        'work.item.create',
        $target,
        null,
        ['job' => 'job-7'],
    );

    $first = \App\Models\AgentDelegation::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->id,
        'source_agent_assignment_id' => $source->id,
        'target_agent_assignment_id' => $target->id,
        'actor_id' => $actor->id,
        'capability' => 'work.item.create',
        'target_context' => ['job' => 'job-7'],
    ]);

    $second = \App\Models\AgentDelegation::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->id,
        'source_agent_assignment_id' => $source->id,
        'target_agent_assignment_id' => $target->id,
        'actor_id' => $actor->id,
        'capability' => 'work.item.create',
        'target_context' => ['job' => 'job-7'],
    ]);

    app(\App\Services\ApprovalRequestService::class)->bindToDelegation($approval, $first);

    expect($approval->refresh()->agent_delegation_id)->toBe($first->getKey())
        ->and(fn () => app(\App\Services\ApprovalRequestService::class)->bindToDelegation($approval, $second))
        ->toThrow(LogicException::class, 'already bound to another delegation');
});

function delegationAssignment(User $actor, Enterprise $enterprise, string $slug): AgentAssignment
{
    Membership::query()->firstOrCreate([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ], [
        'role' => 'owner',
    ]);

    $runtimeClass = match (true) {
        str_contains($slug, 'source') => delegationSourceRuntimeClass(),
        str_contains($slug, 'same-org') => delegationCrossScopeRuntimeClass(),
        default => delegationTargetRuntimeClass(),
    };

    $descriptor = \App\Models\AgentDescriptor::factory()
        ->forRuntimeClass($runtimeClass)
        ->create(['slug' => $slug]);

    return AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);
}

function delegationRequest(
    User $actor,
    AgentAssignment $source,
    string $targetSlug,
    string $capability = 'work.item.create',
    array $targetContext = [],
): AgentDelegationRequest {
    return new AgentDelegationRequest(
        actor: $actor,
        sourceAssignment: $source,
        targetAgentSlug: $targetSlug,
        capability: $capability,
        prompt: 'Perform the delegated work.',
        targetContext: $targetContext,
        correlationId: 'delegation-test-123',
        idempotencyKey: 'delegation-'.$targetSlug.'-'.$capability,
    );
}

function grantDelegationPermission(AgentAssignment $source): void
{
    AgentPermission::factory()->create([
        'agent_assignment_id' => $source->getKey(),
        'capability' => AgentDelegationService::DELEGATION_CAPABILITY,
    ]);
}

it('authorizes same-scope delegation without persisting a second workflow record', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');

    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    $response = app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent', targetContext: ['enterprise_id' => $enterprise->getKey()]),
    );

    expect($response->sourceAssignment->is($source))->toBeTrue()
        ->and($response->targetAssignment->is($target))->toBeTrue()
        ->and($response->sourceDescriptor->slug)->toBe('source-agent')
        ->and($response->targetDescriptor->slug)->toBe('target-agent')
        ->and($response->actor->is($actor))->toBeTrue()
        ->and($response->correlationId)->toBe('delegation-test-123')
        ->and(\App\Models\AgentExecution::query()->count())->toBe(1)
        ->and($response->delegation->status)->toBe(\App\Models\AgentDelegation::STATUS_SUCCEEDED)
        ->and($response->execution)->not->toBeNull();
});

it('rejects a disabled target Agent', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');
    $target->update(['enabled' => false]);

    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    expect(fn () => app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent'),
    ))->toThrow(AuthorizationException::class, 'target Agent assignment is disabled');
});

it('rejects an unassigned target Agent', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    grantDelegationPermission($source);

    expect(fn () => app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'missing-agent'),
    ))->toThrow(AuthorizationException::class, 'not assigned in the source Agent scope');
});

it('rejects cross-organization and cross-Enterprise targets', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $otherEnterprise = Enterprise::factory()->create();

    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $otherOrgTarget = delegationAssignment($actor, $otherEnterprise, 'target-agent');

    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $otherOrgTarget->getKey(),
        'capability' => 'work.item.create',
    ]);

    expect(fn () => app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent'),
    ))->toThrow(AuthorizationException::class, 'outside the source Agent scope');

    $sameOrgOtherEnterprise = Enterprise::factory()->create([
        'organization_id' => $enterprise->organization_id,
    ]);
    $sameOrgTarget = delegationAssignment($actor, $sameOrgOtherEnterprise, 'same-org-target');

    AgentPermission::factory()->create([
        'agent_assignment_id' => $sameOrgTarget->getKey(),
        'capability' => 'work.item.create',
    ]);

    expect(fn () => app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'same-org-target'),
    ))->toThrow(AuthorizationException::class, 'outside the source Agent scope');
});

it('does not allow the target Agent to inherit a capability it does not already possess', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    delegationAssignment($actor, $enterprise, 'target-agent');

    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $source->getKey(),
        'capability' => 'finance.report.generate',
    ]);

    expect(fn () => app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent', 'finance.report.generate'),
    ))->toThrow(AuthorizationException::class, 'target Agent is not authorized');
});

it('preserves approval requirements for source delegation and target capability', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');

    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $source->getKey(),
        'capability' => AgentDelegationService::DELEGATION_CAPABILITY,
    ]);
    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    $request = delegationRequest($actor, $source, 'target-agent', targetContext: ['job' => 'job-7']);

    expect(fn () => app(AgentDelegationService::class)->delegate($request))
        ->toThrow(AuthorizationException::class);

    $sourceApproval = app(\App\Services\ApprovalRequestService::class)->request(
        $actor,
        AgentDelegationService::DELEGATION_CAPABILITY,
        $source,
        null,
        ['job' => 'job-7', 'target_agent_slug' => 'target-agent', 'target_capability' => 'work.item.create'],
    );
    $targetApproval = app(\App\Services\ApprovalRequestService::class)->request(
        $actor,
        'work.item.create',
        $target,
        null,
        ['job' => 'job-7'],
    );

    $approver = User::factory()->create();
    Membership::query()->firstOrCreate([
        'user_id' => $approver->getKey(),
        'organization_id' => $enterprise->organization_id,
    ], [
        'role' => 'owner',
    ]);
    $service = app(AgentDelegationService::class);
    expect(fn () => $service->delegate(new AgentDelegationRequest(
        actor: $actor,
        sourceAssignment: $source,
        targetAgentSlug: 'target-agent',
        capability: 'work.item.create',
        prompt: 'Perform the delegated work.',
        targetContext: ['job' => 'job-7'],
        sourceApproval: $sourceApproval,
        targetApproval: $targetApproval,
        correlationId: 'approval-delegation',
        idempotencyKey: 'approval-delegation-key',
    )))->toThrow(AuthorizationException::class);

    $delegation = \App\Models\AgentDelegation::query()
        ->where('idempotency_key', 'approval-delegation-key')
        ->firstOrFail();

    app(\App\Services\ApprovalRequestService::class)->bindToDelegation($sourceApproval, $delegation);
    app(\App\Services\ApprovalRequestService::class)->bindToDelegation($targetApproval, $delegation);
    app(\App\Services\ApprovalRequestService::class)->approve($sourceApproval, $approver);
    app(\App\Services\ApprovalRequestService::class)->approve($targetApproval, $approver);

    $response = $service->delegate(new AgentDelegationRequest(
        actor: $actor,
        sourceAssignment: $source,
        targetAgentSlug: 'target-agent',
        capability: 'work.item.create',
        prompt: 'Perform the delegated work.',
        targetContext: ['job' => 'job-7'],
        sourceApproval: $sourceApproval,
        targetApproval: $targetApproval,
        correlationId: 'approval-delegation',
        idempotencyKey: 'approval-delegation-key',
    ));

    expect($response->correlationId)->toBe('approval-delegation')
        ->and($sourceApproval->refresh()->consumed_agent_delegation_id)->toBe($delegation->getKey());
});

it('preserves actor and correlation attribution in the delegation response', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');

    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    $response = app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent'),
    );

    expect($response->actor->getKey())->toBe($actor->getKey())
        ->and($response->sourceAssignment->getKey())->toBe($source->getKey())
        ->and($response->targetAssignment->getKey())->toBe($target->getKey())
        ->and($response->correlationId)->toBe('delegation-test-123');
});

it('returns the existing successful delegation for an idempotent retry without executing the target again', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');
    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    $request = delegationRequest($actor, $source, 'target-agent');
    $first = app(AgentDelegationService::class)->delegate($request);
    $second = app(AgentDelegationService::class)->delegate($request);

    expect($second->delegation->is($first->delegation))->toBeTrue()
        ->and(\App\Models\AgentExecution::query()->count())->toBe(1);
});

it('preserves parent execution linkage and historical identity', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');
    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    $parent = \App\Models\AgentExecution::factory()->forAssignment($source)->create();

    $delegation = app(AgentDelegationService::class)->delegate(new AgentDelegationRequest(
        actor: $actor,
        sourceAssignment: $source,
        targetAgentSlug: 'target-agent',
        capability: 'work.item.create',
        prompt: 'Perform the delegated work.',
        parentExecution: $parent,
        idempotencyKey: 'parent-key',
    ))->delegation;

    $delegation->update([
        'organization_name' => 'tampered',
        'source_agent_slug' => 'tampered',
        'prompt' => 'tampered',
        'target_context' => ['tampered' => true],
    ]);
    $delegation->refresh();

    expect($delegation->parent_agent_execution_id)->toBe($parent->getKey())
        ->and($delegation->organization_name)->toBe($enterprise->organization->name)
        ->and($delegation->source_agent_slug)->toBe($source->agentDescriptor->slug)
        ->and($delegation->prompt)->toBe('Perform the delegated work.')
        ->and($delegation->target_context)->toBe([]);
});

it('rejects a failed-to-successful lifecycle transition and supports retry through the same delegation record', function () {
    $delegation = \App\Models\AgentDelegation::factory()->failed('Provider unavailable')->create();

    expect(fn () => $delegation->succeed()->save())
        ->toThrow(LogicException::class, 'cannot transition from [failed] to [succeeded]');

    $delegation->refresh()->retry()->save();
    expect($delegation->status)->toBe(\App\Models\AgentDelegation::STATUS_PENDING)
        ->and($delegation->attempts)->toBe(0);

    $delegation->start()->save();
    expect($delegation->attempts)->toBe(1);
});

it('links a failed delegation to the failed target Agent execution', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');
    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.item.create',
    ]);

    app()->bind(ModelProvider::class, fn (): FakeModelProvider => new FakeModelProvider(
        fn () => throw new \App\AI\Exceptions\ModelProviderException(
            \App\AI\Exceptions\ModelProviderFailureType::Unavailable,
            'fake',
            'provider unavailable',
        ),
    ));

    $request = delegationRequest($actor, $source, 'target-agent');
    expect(fn () => app(AgentDelegationService::class)->delegate($request))
        ->toThrow(\App\AI\Exceptions\ModelProviderException::class);

    $delegation = \App\Models\AgentDelegation::query()->firstOrFail();
    $execution = \App\Models\AgentExecution::query()->firstOrFail();

    expect($delegation->status)->toBe(\App\Models\AgentDelegation::STATUS_FAILED)
        ->and($delegation->target_agent_execution_id)->toBe($execution->getKey())
        ->and($execution->status)->toBe(\App\Models\AgentExecution::STATUS_FAILED)
        ->and($execution->correlation_id)->toBe($delegation->correlation_id);
});