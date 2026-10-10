<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;

it('scopes Work Overview counts to organizations the user can access', function () {
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
    $hiddenProject = Project::factory()->create(['enterprise_id' => $hiddenEnterprise]);

    Task::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    Task::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    WorkItem::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    WorkItem::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    $this->actingAs($user);

    expect(WorkOverview::canAccess())->toBeTrue();

    $page = app(WorkOverview::class);
    $page->mount();

    $overview = collect($page->overview)->keyBy('label');

    expect($overview['Projects']['count'])->toBe(1)
        ->and($overview['Tasks']['count'])->toBe(1)
        ->and($overview['Work Items']['count'])->toBe(1);

    $nodeIds = collect($page->sankeyData['nodes'])->pluck('name');
    $linkPairs = collect($page->sankeyData['links'])->map(fn (array $link): string => $link['source'].'>'.$link['target']);

    expect($nodeIds)
        ->toContain('enterprise:'.$visibleEnterprise->id)
        ->toContain('project:'.$visibleProject->id)
        ->not->toContain('enterprise:'.$hiddenEnterprise->id)
        ->not->toContain('project:'.$hiddenProject->id)
        ->and($linkPairs)->toContain('enterprise:'.$visibleEnterprise->id.'>project:'.$visibleProject->id);
});

it('denies Work Overview access when the user has no organization membership', function () {
    $this->actingAs(User::factory()->create());

    expect(WorkOverview::canAccess())->toBeFalse();
});

it('groups project milestones, work items, and tasks and exposes status and overdue metadata', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise, 'status' => 'active']);
    $milestone = Milestone::factory()->forProject($project)->create([
        'name' => 'Overdue milestone',
        'status' => 'active',
        'due_at' => now()->subDay(),
    ]);
    $workItem = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'name' => 'In progress work item',
        'status' => 'in_progress',
    ]);
    $parentTask = Task::factory()->forProject($project)->create([
        'name' => 'Overdue parent task',
        'status' => 'todo',
        'due_at' => now()->subDay(),
    ]);
    $childTask = Task::factory()->forProject($project)->create([
        'name' => 'Completed child task',
        'parent_task_id' => $parentTask->id,
        'status' => 'done',
        'due_at' => now()->subDay(),
    ]);

    $this->actingAs($user);
    $page = app(WorkOverview::class);
    $page->mount();

    $nodes = collect($page->sankeyData['nodes'])->keyBy('name');
    $links = collect($page->sankeyData['links'])->map(fn (array $link): string => $link['source'].'>'.$link['target']);
    $milestonesGroup = 'group:milestones:'.$project->id;
    $workItemsGroup = 'group:work-items:'.$project->id;
    $tasksGroup = 'group:tasks:'.$project->id;

    expect($nodes->keys()->all())->toContain(
        $milestonesGroup,
        $workItemsGroup,
        $tasksGroup,
        'milestone:'.$milestone->id,
        'work-item:'.$workItem->id,
        'task:'.$parentTask->id,
        'task:'.$childTask->id,
    )
        ->and($nodes['project:'.$project->id]['status'])->toBe('active')
        ->and($nodes['milestone:'.$milestone->id]['overdue'])->toBeTrue()
        ->and($nodes['milestone:'.$milestone->id]['dueAt'])->not->toBeNull()
        ->and($nodes['work-item:'.$workItem->id]['status'])->toBe('in_progress')
        ->and($nodes['task:'.$parentTask->id]['overdue'])->toBeTrue()
        ->and($nodes['task:'.$childTask->id]['overdue'])->toBeFalse()
        ->and($links)->toContain(
            'project:'.$project->id.'>'.$milestonesGroup,
            'project:'.$project->id.'>'.$workItemsGroup,
            'project:'.$project->id.'>'.$tasksGroup,
            $milestonesGroup.'>milestone:'.$milestone->id,
            $workItemsGroup.'>work-item:'.$workItem->id,
            $tasksGroup.'>task:'.$parentTask->id,
            'task:'.$parentTask->id.'>task:'.$childTask->id,
        );
});