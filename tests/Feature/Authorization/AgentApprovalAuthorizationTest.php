<?php

use App\Enums\MembershipRole;
use App\Models\AgentAssignment;
use App\Models\AgentPermission;
use App\Models\ApprovalRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\ApprovalRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use LogicException;

it('blocks a sensitive capability until its approval is approved', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    AgentPermission::factory()->requiresApproval()->create(['agent_assignment_id' => $assignment, 'capability' => 'finance.report.generate']);

    $authorizer = app(AgentCapabilityAuthorizer::class);

    expect($authorizer->allows($assignment, 'finance.report.generate', $organization, null, $actor))->toBeFalse();

    $request = app(ApprovalRequestService::class)->request($actor, 'finance.report.generate', $assignment, null, ['transaction' => 'tx-1']);

    expect($authorizer->allows($assignment, 'finance.report.generate', $organization, null, $actor, $request, null, ['transaction' => 'tx-1']))->toBeFalse();

    $approver = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $approver, 'organization_id' => $organization]);
    app(ApprovalRequestService::class)->approve($request, $approver, 'Approved for execution');

    expect($authorizer->allows($assignment, 'finance.report.generate', $organization, null, $actor, $request, null, ['transaction' => 'tx-1']))->toBeTrue();
});

it('blocks pending and rejected approvals', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    AgentPermission::factory()->requiresApproval()->create(['agent_assignment_id' => $assignment, 'capability' => 'publication.publish']);
    $approver = User::factory()->create();
    Membership::factory()->admin()->create(['user_id' => $approver, 'organization_id' => $organization]);

    $service = app(ApprovalRequestService::class);
    $authorizer = app(AgentCapabilityAuthorizer::class);
    $pending = $service->request($actor, 'publication.publish', $assignment);

    expect($authorizer->allows($assignment, 'publication.publish', $organization, null, $actor, $pending))->toBeFalse();

    $rejected = $service->request($actor, 'publication.publish', $assignment);
    $service->reject($rejected, $approver, 'Not approved');

    expect($authorizer->allows($assignment, 'publication.publish', $organization, null, $actor, $rejected))->toBeFalse();
});

it('denies approval to an unauthorized member and permits an organization administrator', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $member = User::factory()->create();
    $admin = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    $request = app(ApprovalRequestService::class)->request($actor, 'destructive.delete', $assignment);

    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization, 'role' => MembershipRole::Member]);
    Membership::factory()->admin()->create(['user_id' => $admin, 'organization_id' => $organization]);

    expect(Gate::forUser($member)->allows('approve', $request))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('approve', $request))->toBeTrue();
});

it('denies cross-organization approval and authorization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $actor = User::factory()->create();
    $foreignApprover = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    $request = app(ApprovalRequestService::class)->request($actor, 'credentials.use', $assignment);

    Membership::factory()->admin()->create(['user_id' => $foreignApprover, 'organization_id' => $otherOrganization]);

    expect(Gate::forUser($foreignApprover)->allows('approve', $request))->toBeFalse();
    expect(fn () => app(ApprovalRequestService::class)->approve($request, $foreignApprover))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('does not let delegation or a mismatched approval authorize a sensitive capability', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $delegate = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    AgentPermission::factory()->requiresApproval()->create(['agent_assignment_id' => $assignment, 'capability' => 'external.spending']);
    $approver = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $approver, 'organization_id' => $organization]);

    $service = app(ApprovalRequestService::class);
    $request = $service->request($actor, 'external.spending', $assignment, null, ['target' => 'invoice-1']);
    $service->approve($request, $approver);

    $authorizer = app(AgentCapabilityAuthorizer::class);

    expect($authorizer->allows($assignment, 'external.spending', $organization, null, $delegate, $request, null, ['target' => 'invoice-1']))->toBeFalse()
        ->and($authorizer->allows($assignment, 'external.spending', $organization, null, $actor, $request, null, ['target' => 'invoice-2']))->toBeFalse();
});

it('rejects expired approvals at the authorization boundary', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $approver = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    AgentPermission::factory()->requiresApproval()->create(['agent_assignment_id' => $assignment, 'capability' => 'finance.report.generate']);
    Membership::factory()->owner()->create(['user_id' => $approver, 'organization_id' => $organization]);

    $request = app(ApprovalRequestService::class)->request($actor, 'finance.report.generate', $assignment);
    app(ApprovalRequestService::class)->approve($request, $approver);
    $request->expires_at = Carbon::now()->subMinute();
    $request->saveQuietly();

    expect($request->isValid())->toBeFalse()
        ->and(app(AgentCapabilityAuthorizer::class)->allows($assignment, 'finance.report.generate', $organization, null, $actor, $request))->toBeFalse();
});

it('preserves approval attribution and history', function () {
    $organization = Organization::factory()->create(['name' => 'Acme']);
    $actor = User::factory()->create(['name' => 'Agent Actor']);
    $approver = User::factory()->create(['name' => 'Human Approver']);
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    Membership::factory()->owner()->create(['user_id' => $approver, 'organization_id' => $organization]);

    $request = app(ApprovalRequestService::class)->request($actor, 'publication.publish', $assignment, null, ['resource' => 'campaign-7']);
    app(ApprovalRequestService::class)->approve($request, $approver, 'Reviewed and approved');
    $request->refresh();

    $request->actor_name = 'Changed Actor';
    $request->capability = 'different.capability';
    $request->target_context = ['resource' => 'campaign-8'];
    $request->save();
    $request->refresh();

    expect($request->organization_id)->toBe($organization->id)
        ->and($request->organization_name)->toBe('Acme')
        ->and($request->actor_name)->toBe('Agent Actor')
        ->and($request->capability)->toBe('publication.publish')
        ->and($request->target_context)->toContain('campaign-7')
        ->and($request->approver_name)->toBe('Human Approver')
        ->and($request->decision_reason)->toBe('Reviewed and approved')
        ->and($request->status)->toBe(ApprovalRequest::STATUS_APPROVED);
});

it('does not allow a consumed approval to authorize another execution', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $approver = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    AgentPermission::factory()->requiresApproval()->create([
        'agent_assignment_id' => $assignment,
        'capability' => 'finance.report.generate',
    ]);
    Membership::factory()->owner()->create([
        'user_id' => $approver,
        'organization_id' => $organization,
    ]);

    $firstExecution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create();
    $secondExecution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create();
    $service = app(ApprovalRequestService::class);
    $request = $service->request(
        $actor,
        'finance.report.generate',
        $assignment,
        $firstExecution,
        ['transaction' => 'tx-1'],
    );
    $service->approve($request, $approver);
    $service->consume($request, $firstExecution);

    expect($service->matches(
        $request->refresh(),
        $actor,
        $assignment,
        'finance.report.generate',
        $firstExecution,
        ['transaction' => 'tx-1'],
    ))->toBeTrue()
        ->and($service->matches(
            $request->refresh(),
            $actor,
            $assignment,
            'finance.report.generate',
            $secondExecution,
            ['transaction' => 'tx-1'],
        ))->toBeFalse();
});

it('does not require approval for a non-sensitive permitted capability', function () {
    $organization = Organization::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    AgentPermission::factory()->create(['agent_assignment_id' => $assignment, 'capability' => 'marketing.plan']);

    expect(app(AgentCapabilityAuthorizer::class)->allows($assignment, 'marketing.plan', $organization))->toBeTrue();
});

it('does not allow a rejected request to be approved later', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $approver = User::factory()->create();
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    Membership::factory()->owner()->create(['user_id' => $approver, 'organization_id' => $organization]);
    $service = app(ApprovalRequestService::class);
    $request = $service->request($actor, 'destructive.delete', $assignment);
    $service->reject($request, $approver);

    expect(fn () => $service->approve($request, $approver))
        ->toThrow(LogicException::class, 'Only a pending approval request can be decided.');
});