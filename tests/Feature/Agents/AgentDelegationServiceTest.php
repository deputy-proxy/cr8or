<?php

use App\Data\AgentDelegationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentDelegationService;
use Illuminate\Auth\Access\AuthorizationException;

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
            return ['work.create'];
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
            return ['work.create'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }
    });
}

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
    string $capability = 'work.create',
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
        'capability' => 'work.create',
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
        ->and(\App\Models\AgentExecution::query()->count())->toBe(0);
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
        'capability' => 'work.create',
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
        'capability' => 'work.create',
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
        'capability' => 'work.create',
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
        'capability' => 'finance.execute',
    ]);

    expect(fn () => app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent', 'finance.execute'),
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
        'capability' => 'work.create',
    ]);

    $request = delegationRequest($actor, $source, 'target-agent', targetContext: ['job' => 'job-7']);

    expect(fn () => app(AgentDelegationService::class)->delegate($request))
        ->toThrow(AuthorizationException::class);

    $sourceApproval = app(\App\Services\ApprovalRequestService::class)->request(
        $actor,
        AgentDelegationService::DELEGATION_CAPABILITY,
        $source,
        null,
        ['job' => 'job-7', 'target_agent_slug' => 'target-agent', 'target_capability' => 'work.create'],
    );
    $targetApproval = app(\App\Services\ApprovalRequestService::class)->request(
        $actor,
        'work.create',
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
    app(\App\Services\ApprovalRequestService::class)->approve($sourceApproval, $approver);
    app(\App\Services\ApprovalRequestService::class)->approve($targetApproval, $approver);

    $response = app(AgentDelegationService::class)->delegate(new AgentDelegationRequest(
        actor: $actor,
        sourceAssignment: $source,
        targetAgentSlug: 'target-agent',
        capability: 'work.create',
        prompt: 'Perform the delegated work.',
        targetContext: ['job' => 'job-7'],
        sourceApproval: $sourceApproval,
        targetApproval: $targetApproval,
        correlationId: 'approval-delegation',
    ));

    expect($response->correlationId)->toBe('approval-delegation');
});

it('preserves actor and correlation attribution in the delegation response', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $source = delegationAssignment($actor, $enterprise, 'source-agent');
    $target = delegationAssignment($actor, $enterprise, 'target-agent');

    grantDelegationPermission($source);
    AgentPermission::factory()->create([
        'agent_assignment_id' => $target->getKey(),
        'capability' => 'work.create',
    ]);

    $response = app(AgentDelegationService::class)->delegate(
        delegationRequest($actor, $source, 'target-agent'),
    );

    expect($response->actor->getKey())->toBe($actor->getKey())
        ->and($response->sourceAssignment->getKey())->toBe($source->getKey())
        ->and($response->targetAssignment->getKey())->toBe($target->getKey())
        ->and($response->correlationId)->toBe('delegation-test-123');
});
