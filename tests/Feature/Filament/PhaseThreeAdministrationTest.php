<?php

use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Dependencies\DependencyResource;
use App\Filament\Resources\Executions\ExecutionResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\KnowledgeVersions\KnowledgeVersionResource;
use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Assignment;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Gate;

it('scopes assignment and dependency administration to the authenticated organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    $visibleEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $visibleProject = Project::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $visibleTask = Task::factory()->create([
        'enterprise_id' => $visibleEnterprise,
        'project_id' => $visibleProject,
    ]);
    $visibleWorkItem = WorkItem::factory()->create([
        'enterprise_id' => $visibleEnterprise,
        'project_id' => $visibleProject,
    ]);
    $hiddenProject = Project::factory()->create(['enterprise_id' => $hiddenEnterprise]);
    $hiddenTask = Task::factory()->create([
        'enterprise_id' => $hiddenEnterprise,
        'project_id' => $hiddenProject,
    ]);
    $hiddenWorkItem = WorkItem::factory()->create([
        'enterprise_id' => $hiddenEnterprise,
        'project_id' => $hiddenProject,
    ]);

    $visibleAssignment = Assignment::factory()->create([
        'enterprise_id' => $visibleEnterprise,
        'assignable_type' => Task::class,
        'assignable_id' => $visibleTask->id,
    ]);
    $hiddenAssignment = Assignment::factory()->create([
        'enterprise_id' => $hiddenEnterprise,
        'assignable_type' => Task::class,
        'assignable_id' => $hiddenTask->id,
    ]);

    $visibleDependency = Dependency::factory()->create([
        'enterprise_id' => $visibleEnterprise,
        'project_id' => $visibleProject->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $visibleTask->id,
        'successor_type' => WorkItem::class,
        'successor_id' => $visibleWorkItem->id,
    ]);
    $hiddenDependency = Dependency::factory()->create([
        'enterprise_id' => $hiddenEnterprise,
        'project_id' => $hiddenProject->id,
        'predecessor_type' => Task::class,
        'predecessor_id' => $hiddenTask->id,
        'successor_type' => WorkItem::class,
        'successor_id' => $hiddenWorkItem->id,
    ]);

    $this->actingAs($user);

    expect(AssignmentResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($visibleAssignment->id)
        ->not->toContain($hiddenAssignment->id)
        ->and(DependencyResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($visibleDependency->id)
        ->not->toContain($hiddenDependency->id);
});

it('limits phase 3 administration actions to enterprise managers', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    Enterprise::factory()->create(['organization_id' => $organization]);

    $this->actingAs($owner);

    expect(AssignmentResource::canCreate())->toBeTrue()
        ->and(DependencyResource::canCreate())->toBeTrue()
        ->and(KnowledgeVersionResource::canCreate())->toBeTrue()
        ->and(WorkflowResource::canCreate())->toBeFalse()
        ->and(JobResource::canCreate())->toBeFalse()
        ->and(ExecutionResource::canCreate())->toBeFalse();

    $this->actingAs($member);

    expect(AssignmentResource::canCreate())->toBeFalse()
        ->and(DependencyResource::canCreate())->toBeFalse()
        ->and(KnowledgeVersionResource::canCreate())->toBeFalse();
});

it('keeps historical knowledge versions read-only after creation', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = \App\Models\KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    $version = KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_item_id' => $item,
    ]);

    expect(Gate::forUser($user)->allows('view', $version))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $version))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $version))->toBeFalse()
        ->and(KnowledgeVersionResource::getPages())->not->toHaveKey('edit');
});
