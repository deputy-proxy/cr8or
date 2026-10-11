<?php

use App\Models\AgentEpisodicMemory;
use App\Models\AgentRuntimePolicy;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Report;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Policies\Support\RecordOwnership;

it('authorizes an enterprise-owned record only for a member of its organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();

    Membership::factory()->create(['user_id' => $memberA->id, 'organization_id' => $organizationA->id]);
    Membership::factory()->create(['user_id' => $memberB->id, 'organization_id' => $organizationB->id]);

    $record = new AgentEpisodicMemory(['organization_id' => $organizationA->id, 'enterprise_id' => $enterprise->id]);
    $ownership = app(RecordOwnership::class);

    expect($ownership->allows($memberA, $record))->toBeTrue()
        ->and($ownership->allows($memberB, $record))->toBeFalse();
});

it('fails closed when a record enterprise conflicts with its organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $member = User::factory()->create();

    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organizationA->id]);

    $record = new AgentEpisodicMemory(['organization_id' => $organizationB->id, 'enterprise_id' => $enterprise->id]);

    expect(app(RecordOwnership::class)->allows($member, $record))->toBeFalse();
});

it('does not infer ownership for a record without a recognized ownership path', function () {
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id]);

    $record = new AgentEpisodicMemory;

    expect(app(RecordOwnership::class)->allows($member, $record))->toBeFalse();
});

it('resolves indirectly owned report snapshots through their report enterprise', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();

    Membership::factory()->create(['user_id' => $memberA->id, 'organization_id' => $organizationA->id]);
    Membership::factory()->create(['user_id' => $memberB->id, 'organization_id' => $organizationB->id]);

    $report = new Report(['enterprise_id' => $enterprise->id]);
    $snapshot = (new ReportSnapshot)->setRelation('report', $report);

    expect(app(RecordOwnership::class)->allows($memberA, $snapshot))->toBeTrue()
        ->and(app(RecordOwnership::class)->allows($memberB, $snapshot))->toBeFalse();
});

it('allows generic workflow records only to authenticated organization members', function () {
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id]);

    $workflow = new Workflow(['enterprise_specific' => false]);
    $stage = (new WorkflowStage)->setRelation('workflow', $workflow);

    expect(app(RecordOwnership::class)->allows($member, $stage))->toBeTrue()
        ->and(app(RecordOwnership::class)->allows($outsider, $stage))->toBeFalse();
});

it('uses the shared ownership resolver for standard record policy abilities', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id]);

    $record = new AgentEpisodicMemory(['organization_id' => $organization->id, 'enterprise_id' => $enterprise->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($member)->allows('view', $record))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($member)->allows('update', $record))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($member)->allows('delete', $record))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($outsider)->allows('view', $record))->toBeFalse();
});

it('allows the baseline create entry check but requires an authorized target enterprise', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterpriseA = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $enterpriseB = Enterprise::factory()->create(['organization_id' => $organizationB->id]);
    $member = User::factory()->create();
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organizationA->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($member)->allows('create', AgentRuntimePolicy::class))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($member)->allows('createForEnterprise', [AgentRuntimePolicy::class, $enterpriseA]))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($member)->allows('createForEnterprise', [AgentRuntimePolicy::class, $enterpriseB]))->toBeFalse();
});

it('rejects create contexts whose enterprise and organization identifiers disagree', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organizationA->id]);
    $member = User::factory()->create();
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organizationA->id]);

    $record = new AgentEpisodicMemory([
        'organization_id' => $organizationB->id,
        'enterprise_id' => $enterprise->id,
    ]);

    expect(app(RecordOwnership::class)->allows($member, $record))->toBeFalse()
        ->and(app(RecordOwnership::class)->organizationForRecord($record))->toBeNull();
});
