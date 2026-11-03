<?php

use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Milestones\MilestoneResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

it('scopes work administration to the authenticated users organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $visibleEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $visibleProject = Project::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $hiddenProject = Project::factory()->create(['enterprise_id' => $hiddenEnterprise]);
    $visibleInitiative = Initiative::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $hiddenInitiative = Initiative::factory()->create(['enterprise_id' => $hiddenEnterprise]);
    $visibleMilestone = Milestone::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $hiddenMilestone = Milestone::factory()->create(['enterprise_id' => $hiddenEnterprise]);
    $visibleTask = Task::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    $hiddenTask = Task::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);
    $visibleTask = Task::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    $hiddenTask = Task::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    $this->actingAs($user);

    expect(ProjectResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleProject->id)->not->toContain($hiddenProject->id)
        ->and(TaskResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleTask->id)->not->toContain($hiddenTask->id)
        ->and(TaskResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleTask->id)->not->toContain($hiddenTask->id)
        ->and(InitiativeResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleInitiative->id)->not->toContain($hiddenInitiative->id)
        ->and(MilestoneResource::getEloquentQuery()->pluck('id')->all())->toContain($visibleMilestone->id)->not->toContain($hiddenMilestone->id);
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
        ->and(TaskResource::canCreate())->toBeTrue()
        ->and(MilestoneResource::canCreate())->toBeTrue()
        ->and(InitiativeResource::canCreate())->toBeTrue();

    $this->actingAs($member);
    expect(ProjectResource::canCreate())->toBeFalse()
        ->and(TaskResource::canCreate())->toBeFalse()
        ->and(TaskResource::canCreate())->toBeFalse()
        ->and(MilestoneResource::canCreate())->toBeFalse()
        ->and(InitiativeResource::canCreate())->toBeFalse();

    expect($enterprise->organization_id)->toBe($organization->id);
});

it('presents task editing fields in the requested sections with human-readable relationship labels', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $task = Task::factory()->create(['enterprise_id' => $enterprise]);

    $this->actingAs($owner);

    Livewire::test(EditTask::class, ['record' => $task->getRouteKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('enterprise_id', fn (Select $field): bool => $field->getLabel() === 'Enterprise')
        ->assertFormFieldExists('project_id', fn (Select $field): bool => $field->getLabel() === 'Project')
        ->assertFormFieldExists('initiative_id', fn (Select $field): bool => $field->getLabel() === 'Initiative')
        ->assertFormFieldExists('parent_task_id', fn (Select $field): bool => $field->getLabel() === 'Parent Task')
        ->assertFormFieldExists('status')
        ->assertFormFieldExists('priority')
        ->assertFormFieldExists('due_at');
});
