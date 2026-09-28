<?php

use App\Enums\MembershipRole;
use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use App\Models\ApprovalDecision;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApprovalRequestService;
use Illuminate\Support\Carbon;
use LogicException;

function approvalGovernanceContext(): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);

    Membership::factory()->create([
        'user_id' => $actor->id,
        'organization_id' => $organization->id,
        'role' => MembershipRole::Member,
    ]);
    Membership::factory()->admin()->create(['user_id' => $admin->id, 'organization_id' => $organization->id]);
    Membership::factory()->owner()->create(['user_id' => $owner->id, 'organization_id' => $organization->id]);

    return [$organization, $actor, $admin, $owner, $assignment];
}

it('keeps approval authority independent from Agent capability permission', function () {
    [$organization, $actor, $admin, , $assignment] = approvalGovernanceContext();

    $request = app(ApprovalRequestService::class)->request($actor, 'sensitive.operation', $assignment);

    expect(app(ApprovalRequestService::class)->approve($request, $admin)->status)
        ->toBe(ApprovalRequest::STATUS_APPROVED);
});

it('supports a configured multi-stage approval policy', function () {
    [$organization, $actor, $admin, $owner, $assignment] = approvalGovernanceContext();

    ApprovalPolicy::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $assignment->enterprise_id,
        'policy_key' => 'sensitive-operation-v1',
        'capability' => 'sensitive.operation',
        'stages' => [
            ['required' => 2, 'roles' => [MembershipRole::Admin->value, MembershipRole::Owner->value]],
            ['required' => 1, 'roles' => [MembershipRole::Owner->value]],
        ],
        'expires_in_minutes' => 120,
        'allow_self_approval' => false,
        'escalation_roles' => [MembershipRole::Owner->value],
        'enabled' => true,
    ]);

    $service = app(ApprovalRequestService::class);
    $request = $service->request($actor, 'sensitive.operation', $assignment);

    $service->approve($request, $admin, 'First review');

    expect($request->refresh()->status)->toBe(ApprovalRequest::STATUS_PENDING)
        ->and($request->current_stage)->toBe(0)
        ->and(ApprovalDecision::query()->where('approval_request_id', $request->id)->count())->toBe(1);

    $service->approve($request, $owner, 'Second review');

    expect($request->refresh()->status)->toBe(ApprovalRequest::STATUS_PENDING)
        ->and($request->current_stage)->toBe(1);

    $service->approve($request, $owner, 'Final review');

    expect($request->refresh()->status)->toBe(ApprovalRequest::STATUS_APPROVED)
        ->and(ApprovalDecision::query()->where('approval_request_id', $request->id)->count())->toBe(3);
});

it('prevents an approver from voting twice in the same stage', function () {
    [$organization, $actor, $admin, , $assignment] = approvalGovernanceContext();

    ApprovalPolicy::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $assignment->enterprise_id,
        'policy_key' => 'duplicate-vote-test',
        'capability' => 'sensitive.operation',
        'stages' => [['required' => 2, 'roles' => [MembershipRole::Admin->value, MembershipRole::Owner->value]]],
        'expires_in_minutes' => 60,
        'allow_self_approval' => false,
        'escalation_roles' => [MembershipRole::Owner->value],
        'enabled' => true,
    ]);

    $request = app(ApprovalRequestService::class)->request($actor, 'sensitive.operation', $assignment);

    app(ApprovalRequestService::class)->approve($request, $admin);

    expect(fn () => app(ApprovalRequestService::class)->approve($request, $admin))
        ->toThrow(LogicException::class, 'This approver has already decided the active approval stage.');
});

it('rejects self approval unless a policy explicitly allows it', function () {
    [$organization, $actor, , , $assignment] = approvalGovernanceContext();

    ApprovalPolicy::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $assignment->enterprise_id,
        'policy_key' => 'no-self-approval',
        'capability' => 'sensitive.operation',
        'stages' => [['required' => 1, 'roles' => [MembershipRole::Member->value]]],
        'expires_in_minutes' => 60,
        'allow_self_approval' => false,
        'escalation_roles' => [MembershipRole::Owner->value],
        'enabled' => true,
    ]);

    $request = app(ApprovalRequestService::class)->request($actor, 'sensitive.operation', $assignment);

    expect(fn () => app(ApprovalRequestService::class)->approve($request, $actor))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('supports explicit cancellation with immutable history', function () {
    [, $actor, , , $assignment] = approvalGovernanceContext();

    $service = app(ApprovalRequestService::class);
    $request = $service->request($actor, 'sensitive.operation', $assignment);

    $service->cancel($request, $actor, 'No longer needed');

    $decision = ApprovalDecision::query()->where('approval_request_id', $request->id)->firstOrFail();

    expect($request->refresh()->status)->toBe(ApprovalRequest::STATUS_CANCELLED)
        ->and($decision->decision)->toBe(ApprovalDecision::DECISION_CANCELLED)
        ->and(fn () => $decision->update(['reason' => 'rewritten']))
        ->toThrow(LogicException::class, 'Approval decisions are immutable.');
});

it('expires overdue requests without inventing an approver', function () {
    [, $actor, , , $assignment] = approvalGovernanceContext();

    $service = app(ApprovalRequestService::class);
    $request = $service->request($actor, 'sensitive.operation', $assignment);
    $request->expires_at = Carbon::now()->subMinute();
    $request->saveQuietly();

    $service->expire($request);

    $decision = ApprovalDecision::query()->where('approval_request_id', $request->id)->firstOrFail();

    expect($request->refresh()->status)->toBe(ApprovalRequest::STATUS_EXPIRED)
        ->and($request->approver_id)->toBeNull()
        ->and($decision->actor_id)->toBeNull()
        ->and($decision->actor_name)->toBe('system');
});

it('marks a pending request stale when the originating operation is invalidated', function () {
    [, $actor, , , $assignment] = approvalGovernanceContext();

    $request = app(ApprovalRequestService::class)->request($actor, 'sensitive.operation', $assignment);

    app(ApprovalRequestService::class)->markStale($request, $actor, 'Input version changed.');

    expect($request->refresh()->status)->toBe(ApprovalRequest::STATUS_STALE)
        ->and($request->stale_reason)->toBe('Input version changed.');
});

it('binds approval reuse to an exact request fingerprint', function () {
    [$organization, $actor, $admin, , $assignment] = approvalGovernanceContext();

    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'sensitive.operation',
    ]);

    $service = app(ApprovalRequestService::class);
    $request = $service->request($actor, 'sensitive.operation', $assignment, null, ['input_version' => 1]);
    $service->approve($request, $admin);

    expect($service->matches(
        $request,
        $actor,
        $assignment,
        'sensitive.operation',
        null,
        ['input_version' => 1],
    ))->toBeTrue()
        ->and($service->matches(
            $request,
            $actor,
            $assignment,
            'sensitive.operation',
            null,
            ['input_version' => 2],
        ))->toBeFalse();
});

it('does not let a stale or cancelled request remain valid', function () {
    [, $actor, , , $assignment] = approvalGovernanceContext();
    $service = app(ApprovalRequestService::class);

    $cancelled = $service->request($actor, 'sensitive.operation', $assignment);
    $service->cancel($cancelled, $actor);

    $stale = $service->request($actor, 'sensitive.operation', $assignment);
    $service->markStale($stale, $actor, 'Source changed.');

    expect($cancelled->refresh()->isValid())->toBeFalse()
        ->and($stale->refresh()->isValid())->toBeFalse();
});