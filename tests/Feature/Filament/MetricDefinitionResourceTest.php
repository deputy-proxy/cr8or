<?php

use App\Enums\MembershipRole;
use App\Filament\Resources\MetricDefinitions\MetricDefinitionResource;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\MetricDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Policies\MetricDefinitionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes create and edit pages for metric definitions', function () {
    expect(array_keys(MetricDefinitionResource::getPages()))
        ->toBe(['index', 'create', 'edit']);
});

it('allows organization owners and admins to create metric definitions only for their enterprises', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    Membership::factory()->admin()->create([
        'user_id' => $admin->id,
        'organization_id' => $organization->id,
    ]);
    Membership::factory()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
    ]);

    $policy = new MetricDefinitionPolicy;

    expect($policy->create($owner))->toBeTrue()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->create($member))->toBeFalse()
        ->and($policy->create($outsider))->toBeFalse()
        ->and($policy->createForEnterprise($owner, $enterprise))->toBeTrue()
        ->and($policy->createForEnterprise($admin, $enterprise))->toBeTrue()
        ->and($policy->createForEnterprise($member, $enterprise))->toBeFalse()
        ->and($policy->createForEnterprise($outsider, $enterprise))->toBeFalse();
});

it('allows members to view but only owners and admins to edit metric definitions in their organization', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    Membership::factory()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
    ]);

    $metricDefinition = new MetricDefinition(['enterprise_id' => $enterprise->id]);
    $metricDefinition->setRelation('enterprise', $enterprise);
    $policy = new MetricDefinitionPolicy;

    expect($policy->view($owner, $metricDefinition))->toBeTrue()
        ->and($policy->view($member, $metricDefinition))->toBeTrue()
        ->and($policy->view($outsider, $metricDefinition))->toBeFalse()
        ->and($policy->update($owner, $metricDefinition))->toBeTrue()
        ->and($policy->update($member, $metricDefinition))->toBeFalse()
        ->and($policy->update($outsider, $metricDefinition))->toBeFalse()
        ->and($policy->delete($owner, $metricDefinition))->toBeFalse();
});
