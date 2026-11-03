<?php

use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

it('groups tasks by enterprise by default and supports all requested groupings and filters', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise]);
    $initiative = Initiative::factory()->create(['enterprise_id' => $enterprise]);

    $projectTask = Task::factory()->create([
        'enterprise_id' => $enterprise,
        'project_id' => $project,
        'name' => 'Project task',
        'status' => 'in_progress',
        'priority' => 'high',
    ]);
    $initiativeTask = Task::factory()->create([
        'enterprise_id' => $enterprise,
        'initiative_id' => $initiative,
        'name' => 'Initiative task',
        'status' => 'todo',
        'priority' => 'normal',
    ]);

    $this->actingAs($owner);

    Livewire::test(ListTasks::class)
        ->assertSet('tableGrouping', 'enterprise_id:asc')
        ->assertCanSeeTableRecords([$projectTask, $initiativeTask])
        ->set('tableGrouping', 'project_id:asc')
        ->assertCanSeeTableRecords([$projectTask, $initiativeTask])
        ->set('tableGrouping', 'initiative_id:asc')
        ->assertCanSeeTableRecords([$projectTask, $initiativeTask])
        ->set('tableGrouping', 'status:asc')
        ->assertCanSeeTableRecords([$projectTask, $initiativeTask])
        ->filterTable('project_id', $project->id)
        ->assertCanSeeTableRecords([$projectTask])
        ->assertCanNotSeeTableRecords([$initiativeTask])
        ->removeTableFilter('project_id')
        ->filterTable('initiative_id', $initiative->id)
        ->assertCanSeeTableRecords([$initiativeTask])
        ->assertCanNotSeeTableRecords([$projectTask])
        ->removeTableFilter('initiative_id')
        ->filterTable('status', 'in_progress')
        ->assertCanSeeTableRecords([$projectTask])
        ->assertCanNotSeeTableRecords([$initiativeTask])
        ->removeTableFilter('status')
        ->filterTable('priority', 'high')
        ->assertCanSeeTableRecords([$projectTask])
        ->assertCanNotSeeTableRecords([$initiativeTask]);
});
