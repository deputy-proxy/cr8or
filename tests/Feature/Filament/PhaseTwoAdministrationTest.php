<?php

use App\Filament\Resources\AgentAssignments\AgentAssignmentResource;
use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Filament\Resources\AgentDescriptors\AgentDescriptorResource;
use App\Filament\Resources\AgentExecutions\AgentExecutionResource;
use App\Filament\Resources\AgentPermissions\AgentPermissionResource;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\ExpertDescriptors\ExpertDescriptorResource;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentPermission;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

it('scopes every phase two organization resource to the authenticated organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);

    $descriptor = AgentDescriptor::factory()->create();
    $assignment = AgentAssignment::factory()->create(['agent_descriptor_id' => $descriptor, 'organization_id' => $organization]);
    $foreignAssignment = AgentAssignment::factory()->create(['agent_descriptor_id' => $descriptor, 'organization_id' => $otherOrganization]);
    $permission = AgentPermission::factory()->create(['agent_assignment_id' => $assignment]);
    $foreignPermission = AgentPermission::factory()->create(['agent_assignment_id' => $foreignAssignment]);
    $execution = AgentExecution::factory()->create(['organization_id' => $organization]);
    $foreignExecution = AgentExecution::factory()->create(['organization_id' => $otherOrganization]);
    $decision = AgentDecision::factory()->create(['organization_id' => $organization]);
    $foreignDecision = AgentDecision::factory()->create(['organization_id' => $otherOrganization]);
    $approvalActor = User::factory()->create();
    $foreignApprovalActor = User::factory()->create();
    $approval = app(\App\Services\ApprovalRequestService::class)->request($approvalActor, 'test.approve', $assignment);
    $foreignApproval = app(\App\Services\ApprovalRequestService::class)->request($foreignApprovalActor, 'test.approve', $foreignAssignment);

    $this->actingAs($owner);

    expect(AgentAssignmentResource::getEloquentQuery()->pluck('id')->all())->toContain($assignment->id)->not->toContain($foreignAssignment->id)
        ->and(AgentPermissionResource::getEloquentQuery()->pluck('id')->all())->toContain($permission->id)->not->toContain($foreignPermission->id)
        ->and(AgentExecutionResource::getEloquentQuery()->pluck('id')->all())->toContain($execution->id)->not->toContain($foreignExecution->id)
        ->and(AgentDecisionResource::getEloquentQuery()->pluck('id')->all())->toContain($decision->id)->not->toContain($foreignDecision->id)
        ->and(ApprovalRequestResource::getEloquentQuery()->pluck('id')->all())->toContain($approval->id)->not->toContain($foreignApproval->id);
});

it('does not expose phase two administration to users without organization membership', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(AgentDescriptorResource::canViewAny())->toBeFalse()
        ->and(AgentAssignmentResource::canViewAny())->toBeFalse()
        ->and(AgentPermissionResource::canViewAny())->toBeFalse()
        ->and(AgentExecutionResource::canViewAny())->toBeFalse()
        ->and(AgentDecisionResource::canViewAny())->toBeFalse()
        ->and(ApprovalRequestResource::canViewAny())->toBeFalse()
        ->and(ExpertDescriptorResource::canViewAny())->toBeFalse();
});

it('allows owners and admins to manage assignments and permissions but not historical records', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->admin()->create(['user_id' => $admin, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization]);
    $permission = AgentPermission::factory()->create(['agent_assignment_id' => $assignment]);
    $execution = AgentExecution::factory()->create(['organization_id' => $organization]);
    $decision = AgentDecision::factory()->create(['organization_id' => $organization]);
    $approvalActor = User::factory()->create();
    $approval = app(\App\Services\ApprovalRequestService::class)->request($approvalActor, 'test.approve', $assignment);

    expect(Gate::forUser($owner)->allows('update', $assignment))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $assignment))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $assignment))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $permission))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $permission))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $permission))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $execution))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $execution))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $decision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $decision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('approve', $approval))->toBeTrue();
});

it('keeps runtime class fields read-only in agent and expert descriptor forms', function () {
    $agentSchema = AgentDescriptorResource::form(new Schema);
    $expertSchema = ExpertDescriptorResource::form(new Schema);

    $agentRuntimeClass = $agentSchema->getComponents()[1];
    $expertRuntimeClass = $expertSchema->getComponents()[1];

    expect($agentRuntimeClass->isDisabled())->toBeTrue()
        ->and($agentRuntimeClass->isDehydrated())->toBeFalse()
        ->and($expertRuntimeClass->isDisabled())->toBeTrue()
        ->and($expertRuntimeClass->isDehydrated())->toBeFalse();
});
it('does not permit cross-organization relation access for agent assignments', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $assignment = AgentAssignment::factory()->create(['organization_id' => $organization, 'enterprise_id' => $foreignEnterprise]);

    expect(Gate::forUser($owner)->allows('view', $assignment))->toBeFalse();
});
