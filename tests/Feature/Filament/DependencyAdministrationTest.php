<?php

use App\Filament\Resources\Dependencies\DependencyResource;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;

it('places Work Dependencies under Work and exposes the complete endpoint set', function () {
    expect(DependencyResource::getNavigationGroup())->toBe('Work')
        ->and(DependencyResource::getNavigationSort())->toBe(10)
        ->and(DependencyResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = DependencyResource::form(new \Filament\Schemas\Schema);

    expect(DependencyResource::getNavigationLabel())->toBe('Dependencies');
});

it('keeps Dependency administration policy-authoritative', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner->id, 'organization_id' => $organization->id]);
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($owner);
    expect(DependencyResource::canCreate())->toBeTrue();

    $this->actingAs($member);
    expect(DependencyResource::canCreate())->toBeFalse();
});

it('keeps WorkContextAssembler dependencies inside the authorized Enterprise graph', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization->id]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $item = WorkItem::factory()->create(['enterprise_id' => $enterprise->id]);
    $otherTask = Task::factory()->create(['enterprise_id' => $otherEnterprise->id]);

    $dependency = Dependency::factory()->create([
        'enterprise_id' => $enterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $task->id,
        'successor_type' => WorkItem::class,
        'successor_id' => $item->id,
    ]);

    expect(app(\App\Services\WorkContextAssembler::class)->assemble($user, $enterprise)['dependencies'])
        ->toHaveCount(1)
        ->sequence(fn ($entry) => $entry->id->toBe($dependency->id));

    $other = Dependency::factory()->create([
        'enterprise_id' => $otherEnterprise->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $otherTask->id,
        'successor_type' => WorkItem::class,
        'successor_id' => WorkItem::factory()->create(['enterprise_id' => $otherEnterprise->id])->id,
    ]);

    expect(app(\App\Services\WorkContextAssembler::class)->assemble($user, $enterprise)['dependencies'])
        ->toHaveCount(1)
        ->sequence(fn ($entry) => $entry->id->toBe($dependency->id));
});
