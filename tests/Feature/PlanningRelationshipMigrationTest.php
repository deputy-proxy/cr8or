<?php

use App\Models\Enterprise;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills Enterprise ownership and target dates while removing obsolete planning links', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->getKey()]);

    $initiativeId = DB::table('initiatives')->insertGetId([
        'strategy_id' => $strategy->getKey(),
        'name' => 'Legacy initiative',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $project = Project::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    DB::table('projects')->where('id', $project->getKey())->update(['initiative_id' => $initiativeId]);

    $milestoneId = DB::table('milestones')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Legacy milestone',
        'description' => null,
        'due_at' => '2027-04-18 14:30:00',
        'status' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unchangedMilestoneId = DB::table('milestones')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Unchanged legacy milestone',
        'description' => null,
        'due_at' => '2027-05-10 09:45:00',
        'status' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $deletedMilestoneId = DB::table('milestones')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Deleted legacy milestone',
        'description' => null,
        'due_at' => '2027-06-01 08:00:00',
        'status' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $taskId = DB::table('tasks')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => null,
        'parent_task_id' => null,
        'name' => 'Legacy Enterprise task',
        'description' => null,
        'status' => 'todo',
        'priority' => 'normal',
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $projectTaskId = DB::table('tasks')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'parent_task_id' => null,
        'name' => 'Legacy Project task',
        'description' => null,
        'status' => 'todo',
        'priority' => 'normal',
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration->up();

    expect(DB::table('initiatives')->where('id', $initiativeId)->value('enterprise_id'))->toBe($enterprise->getKey())
        ->and(Schema::hasColumn('projects', 'initiative_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'project_id'))->toBeFalse()
        ->and(DB::table('milestones')->where('id', $milestoneId)->value('target_date'))->toBe('2027-04-18')
        ->and(DB::table('tasks')->where('id', $taskId)->value('initiative_id'))->toBeNull()
        ->and(DB::table('tasks')->where('id', $taskId)->value('project_id'))->toBeNull()
        ->and(DB::table('tasks')->where('id', $projectTaskId)->value('project_id'))->toBe($project->getKey())
        ->and(DB::table('tasks')->where('id', $projectTaskId)->value('initiative_id'))->toBeNull()
        ->and(DB::table('planning_relationship_migration_archive')->where('entity_type', 'project_initiative')->count())->toBe(1)
        ->and(DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_project')->count())->toBe(3)
        ->and(DB::table('planning_relationship_migration_archive')->where('entity_type', 'task_project')->count())->toBe(1);

    DB::table('milestones')->where('id', $milestoneId)->update(['target_date' => '2027-04-19']);
    DB::table('milestones')->where('id', $deletedMilestoneId)->delete();

    $migration->down();

    expect(Schema::hasColumn('projects', 'initiative_id'))->toBeTrue()
        ->and(Schema::hasColumn('milestones', 'project_id'))->toBeTrue()
        ->and(Schema::hasColumn('milestones', 'due_at'))->toBeTrue()
        ->and(Schema::hasColumn('initiatives', 'enterprise_id'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'initiative_id'))->toBeFalse()
        ->and(DB::table('projects')->where('id', $project->getKey())->value('initiative_id'))->toBe($initiativeId)
        ->and(DB::table('milestones')->where('id', $milestoneId)->value('project_id'))->toBe($project->getKey())
        ->and(DB::table('milestones')->where('id', $milestoneId)->value('due_at'))->toBe('2027-04-19 00:00:00')
        ->and(DB::table('milestones')->where('id', $unchangedMilestoneId)->value('due_at'))->toBe('2027-05-10 09:45:00')
        ->and(DB::table('milestones')->where('id', $deletedMilestoneId)->exists())->toBeFalse()
        ->and(DB::table('tasks')->where('id', $taskId)->value('project_id'))->toBeNull()
        ->and(DB::table('tasks')->where('id', $projectTaskId)->value('project_id'))->toBe($project->getKey())
        ->and(Schema::hasTable('planning_relationship_migration_archive'))->toBeFalse();
});

it('fails before schema changes when a legacy Project references an Initiative from another Enterprise', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->getKey()]);
    $initiativeId = DB::table('initiatives')->insertGetId([
        'strategy_id' => $strategy->getKey(),
        'name' => 'Foreign initiative',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    DB::table('projects')->where('id', $project->getKey())->update(['initiative_id' => $initiativeId]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'outside its Enterprise')
        ->and(Schema::hasColumn('initiatives', 'enterprise_id'))->toBeFalse()
        ->and(Schema::hasTable('planning_relationship_migration_archive'))->toBeFalse();
});
