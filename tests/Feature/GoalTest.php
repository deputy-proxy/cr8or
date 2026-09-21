<?php

use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('creates a goal belonging to an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Increase recurring revenue',
    ]);

    expect($goal->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->goals->contains($goal))->toBeTrue();
});

it('keeps goal ownership when unrelated attributes change', function () {
    $enterprise = Enterprise::factory()->create();
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise]);

    $goal->update(['name' => 'Updated Goal', 'status' => 'archived']);

    expect($goal->refresh()->enterprise->is($enterprise))->toBeTrue();
});

it('enforces goal authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $goal = Goal::factory()->create(['enterprise_id' => $enterprise]);
    $foreignGoal = Goal::factory()->create([
        'enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization]),
    ]);

    expect(Gate::forUser($owner)->allows('view', $goal))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $goal))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $goal))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $goal))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignGoal))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [Goal::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Goal::class, $enterprise]))->toBeFalse();
});

it('keeps the goal schema limited to phase 1 fields', function () {
    expect(Schema::getColumnListing('goals'))->toBe([
        'id', 'enterprise_id', 'name', 'description', 'status', 'created_at', 'updated_at',
    ]);
});