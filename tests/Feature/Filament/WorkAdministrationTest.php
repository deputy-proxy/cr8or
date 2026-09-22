<?php

use App\Filament\Resources\Milestones\MilestoneResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;

it('scopes work administration to the authenticated users organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $visibleEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $visibleProject = Project::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $hiddenProject = Project::factory()->create(['enterprise_id' => $hiddenEnterprise]);
    $visibleTask = Task::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    $hiddenTask = Task::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);
    $visibleWorkItem = WorkItem::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    $hiddenWorkItem = WorkItem::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    $this->actingAs($user);

    expect(ProjectResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleProject->id)->not->toContain($hiddenProject->id)
        ->and(TaskResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleTask->id)->not->toContain($hiddenTask->id)
        ->and(WorkItemResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleWorkItem->id)->not->toContain($hiddenWorkItem->id)
        ->and(MilestoneResource::getEloquentQuery()->count())->toBe(0);
});

it('allows only enterprise managers to create work records in Filament', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $this->actingAs($owner);
    expect(ProjectResource::canCreate())->toBeTrue()
        ->and(TaskResource::canCreate())->toBeTrue()
        ->and(WorkItemResource::canCreate())->toBeTrue()
        ->and(MilestoneResource::canCreate())->toBeTrue();

    $this->actingAs($member);
    expect(ProjectResource::canCreate())->toBeFalse()
        ->and(TaskResource::canCreate())->toBeFalse()
        ->and(WorkItemResource::canCreate())->toBeFalse()
        ->and(MilestoneResource::canCreate())->toBeFalse();

    expect($enterprise->organization_id)->toBe($organization->id);
});
