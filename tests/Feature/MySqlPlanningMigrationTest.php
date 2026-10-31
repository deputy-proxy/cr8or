<?php

use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('This integration suite requires MySQL.');
    }
});

it('has both planning migrations applied with the expected MySQL relationships', function () {
    expect(DB::getDriverName())->toBe('mysql')
        ->and(DB::table('migrations')->where('migration', '2026_10_31_000001_remove_plan_domain_model')->exists())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', '2026_10_31_000002_restructure_enterprise_planning_relationships')->exists())->toBeTrue()
        ->and(Schema::hasColumn('initiatives', 'enterprise_id'))->toBeTrue()
        ->and(Schema::hasColumn('initiatives', 'strategy_id'))->toBeTrue()
        ->and(Schema::hasColumn('projects', 'initiative_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'target_date'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'project_id'))->toBeTrue()
        ->and(Schema::hasColumn('tasks', 'initiative_id'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('milestones'))->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['enterprise_id']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('tasks'))->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['project_id']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('tasks'))->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['initiative_id']))->toBeTrue()
        ->and(Schema::hasIndex('milestones', 'milestones_enterprise_id_idx'))->toBeTrue();
});

it('rejects a Task assigned to both a Project and Initiative through the model invariant on MySQL', function () {
    expect(DB::getDriverName())->toBe('mysql');

    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->getKey()]);
    $initiative = Initiative::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'strategy_id' => $strategy->getKey(),
    ]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    expect(fn () => Task::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'initiative_id' => $initiative->getKey(),
    ]))->toThrow(\LogicException::class, 'A Task can belong to a Project or Initiative, not both.')
        ->and(fn () => app(TaskService::class)->create(new User, $enterprise, [
            'project_id' => $project->getKey(),
            'initiative_id' => $initiative->getKey(),
            'name' => 'Invalid dual-parent task',
        ]))->toThrow(\InvalidArgumentException::class, 'A Task can belong to a Project or Initiative, not both.');
});
