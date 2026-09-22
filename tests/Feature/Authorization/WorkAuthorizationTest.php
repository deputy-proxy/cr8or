<?php

use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('keeps dependencies organization safe', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $taskA = Task::factory()->create(['enterprise_id' => $enterprise]);
    $taskB = Task::factory()->create(['enterprise_id' => $enterprise]);
    $dependency = Dependency::factory()->create([
        'enterprise_id' => $enterprise,
        'predecessor_type' => Task::class,
        'predecessor_id' => $taskA->id,
        'successor_type' => Task::class,
        'successor_id' => $taskB->id,
    ]);

    expect(Gate::forUser($user)->allows('view', $dependency))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $dependency))->toBeFalse();
});

it('allows authorized enterprise managers to administer work', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise]);

    expect(Gate::forUser($owner)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $task))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $task))->toBeFalse();
});
