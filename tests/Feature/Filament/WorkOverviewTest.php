<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

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

    Task::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    Task::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    $this->actingAs($user);

    expect(WorkOverview::canAccess())->toBeTrue();

    $page = app(WorkOverview::class);
    $page->mount();

    $overview = collect($page->overview)->keyBy('label');

    expect($overview['Projects']['count'])->toBe(1)
        ->and($overview['Tasks']['count'])->toBe(2);

    $flatten = function (array $node, array &$nodes, array &$links) use (&$flatten): void {
        foreach ($node['children'] ?? [] as $child) {
            $nodes[] = $child;
            $links[] = $node['name'].'>'.$child['name'];
            $flatten($child, $nodes, $links);
        }
    };
    $flatNodes = [];
    $linkPairs = [];
    foreach ($page->treeData as $root) {
        $flatNodes[] = $root;
        $flatten($root, $flatNodes, $linkPairs);
    }
    $nodes = collect($flatNodes)->keyBy('name');
    $nodeIds = $nodes->keys();

    expect($nodeIds)
        ->toContain('root:work-overview')
        ->toContain('enterprise:'.$visibleEnterprise->id)
        ->toContain('project:'.$visibleProject->id)
        ->not->toContain('enterprise:'.$hiddenEnterprise->id)
        ->not->toContain('project:'.$hiddenProject->id)
        ->and($linkPairs)->toContain('enterprise:'.$visibleEnterprise->id.'>project:'.$visibleProject->id)
        ->and($page->treeData[0]['children'][0]['name'])->toBe('enterprise:'.$visibleEnterprise->id)
        ->and(parse_url($nodes['enterprise:'.$visibleEnterprise->id]['url'], PHP_URL_PATH))->toEndWith('/'.$visibleEnterprise->id.'/edit')
        ->and(parse_url($nodes['project:'.$visibleProject->id]['url'], PHP_URL_PATH))->toEndWith('/'.$visibleProject->id.'/edit');
});

it('denies Work Overview access when the user has no organization membership', function () {
    $this->actingAs(User::factory()->create());

    expect(WorkOverview::canAccess())->toBeFalse();
});

it('groups project milestones, tasks, and tasks and exposes status and overdue metadata', function () {
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
    $workItem = Task::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'name' => 'In progress task',
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

    $flatNodes = [];
    $linkPairs = [];
    $flatten = function (array $node) use (&$flatten, &$flatNodes, &$linkPairs): void {
        $flatNodes[] = $node;
        foreach ($node['children'] ?? [] as $child) {
            $linkPairs[] = $node['name'].'>'.$child['name'];
            $flatten($child);
        }
    };
    foreach ($page->treeData as $root) {
        $flatten($root);
    }
    $nodes = collect($flatNodes)->keyBy('name');
    $links = collect($linkPairs);
    $milestonesGroup = 'group:milestones:'.$project->id;
    $tasksGroup = 'group:tasks:project:'.$project->id;

    expect($nodes->keys()->all())->toContain(
        $milestonesGroup,
        $tasksGroup,
        'milestone:'.$milestone->id,
        'task:'.$workItem->id,
        'task:'.$parentTask->id,
        'task:'.$childTask->id,
    )
        ->and($nodes['project:'.$project->id]['status'])->toBe('active')
        ->and($nodes['milestone:'.$milestone->id]['overdue'])->toBeTrue()
        ->and($nodes['milestone:'.$milestone->id]['dueAt'])->not->toBeNull()
        ->and($nodes['task:'.$workItem->id]['status'])->toBe('in_progress')
        ->and($nodes['task:'.$parentTask->id]['overdue'])->toBeTrue()
        ->and($nodes['task:'.$childTask->id]['overdue'])->toBeFalse()
        ->and($nodes[$milestonesGroup]['url'])->toBe('#')
        ->and($nodes[$tasksGroup]['url'])->toBe('#')
        ->and(parse_url($nodes['milestone:'.$milestone->id]['url'], PHP_URL_PATH))->toEndWith('/'.$milestone->id.'/edit')
        ->and(parse_url($nodes['task:'.$workItem->id]['url'], PHP_URL_PATH))->toEndWith('/'.$workItem->id.'/edit')
        ->and(parse_url($nodes['task:'.$parentTask->id]['url'], PHP_URL_PATH))->toEndWith('/'.$parentTask->id.'/edit')
        ->and($links)->toContain(
            'project:'.$project->id.'>'.$milestonesGroup,
            'project:'.$project->id.'>'.$tasksGroup,
            $milestonesGroup.'>milestone:'.$milestone->id,
            $tasksGroup.'>task:'.$workItem->id,
            $tasksGroup.'>task:'.$parentTask->id,
            'task:'.$parentTask->id.'>task:'.$childTask->id,
        );
});
it('does not expose edit links to members who can view but cannot edit records', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);

    $this->actingAs($user);
    $page = app(WorkOverview::class);
    $page->mount();

    $flatten = function (array $node, array &$nodes) use (&$flatten): void {
        $nodes[$node['name']] = $node;
        foreach ($node['children'] ?? [] as $child) {
            $flatten($child, $nodes);
        }
    };
    $nodes = [];
    foreach ($page->treeData as $root) {
        $flatten($root, $nodes);
    }

    expect($nodes['enterprise:'.$enterprise->id]['url'])->toBe('#')
        ->and($nodes['project:'.$project->id]['url'])->toBe('#');
});
