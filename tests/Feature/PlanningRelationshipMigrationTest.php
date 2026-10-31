<?php

use App\Models\Enterprise;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Strategy;
use Illuminate\Database\Schema\Blueprint;
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
        ->and(Schema::hasIndex('milestones', 'milestones_enterprise_id_idx'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('milestones'))->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['enterprise_id']))->toBeTrue()
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
        ->and(Schema::hasIndex('milestones', 'milestones_enterprise_id_idx'))->toBeFalse()
        ->and(Schema::hasIndex('milestones', ['enterprise_id', 'project_id']))->toBeTrue()
        ->and(Schema::hasTable('planning_relationship_migration_archive'))->toBeFalse();
});

it('resumes the known MySQL milestone index failure state without duplicating archive records', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    $enterprise = Enterprise::factory()->create();
    $objective = Objective::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $strategy = Strategy::factory()->create(['objective_id' => $objective->getKey()]);
    $initiativeId = DB::table('initiatives')->insertGetId([
        'strategy_id' => $strategy->getKey(),
        'name' => 'Interrupted migration initiative',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $project = Project::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    DB::table('projects')->where('id', $project->getKey())->update(['initiative_id' => $initiativeId]);

    $milestoneId = DB::table('milestones')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'name' => 'Interrupted migration milestone',
        'description' => null,
        'due_at' => '2027-08-12 16:45:00',
        'status' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $taskId = DB::table('tasks')->insertGetId([
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $project->getKey(),
        'parent_task_id' => null,
        'name' => 'Interrupted migration task',
        'description' => null,
        'status' => 'todo',
        'priority' => 'normal',
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Schema::create('planning_relationship_migration_archive', function (Blueprint $table): void {
        $table->id();
        $table->string('entity_type');
        $table->unsignedBigInteger('entity_id');
        $table->unsignedBigInteger('related_id')->nullable();
        $table->longText('payload')->nullable();
        $table->timestamps();
        $table->index(['entity_type', 'entity_id'], 'planning_archive_entity_entity_idx');
    });

    $milestone = (array) DB::table('milestones')->where('id', $milestoneId)->first();
    DB::table('planning_relationship_migration_archive')->insert([
        ['entity_type' => 'project_initiative', 'entity_id' => $project->getKey(), 'related_id' => $initiativeId, 'payload' => null, 'created_at' => now(), 'updated_at' => now()],
        ['entity_type' => 'milestone_record', 'entity_id' => $milestoneId, 'related_id' => null, 'payload' => json_encode($milestone, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
        ['entity_type' => 'milestone_project', 'entity_id' => $milestoneId, 'related_id' => $project->getKey(), 'payload' => null, 'created_at' => now(), 'updated_at' => now()],
        ['entity_type' => 'task_project', 'entity_id' => $taskId, 'related_id' => $project->getKey(), 'payload' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $sqlite = DB::getDriverName() === 'sqlite';
    if ($sqlite) {
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    try {
        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::table('initiatives')->where('id', $initiativeId)->update(['enterprise_id' => $enterprise->getKey()]);
        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->dropForeign(['strategy_id']);
            $table->foreignId('strategy_id')->nullable()->change();
            $table->foreign('strategy_id')->references('id')->on('strategies')->nullOnDelete();
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initiative_id');
        });
        Schema::table('milestones', function (Blueprint $table): void {
            $table->date('target_date')->nullable()->after('description');
        });
        DB::table('milestones')->update(['target_date' => DB::raw('DATE(due_at)')]);
        // SQLite rebuilds the referenced Projects table for this simulated DDL and applies ON DELETE cascades.
        // Restore the archived dependent row to model MySQL's ALTER TABLE behavior at this failure point.
        if ($sqlite && ! DB::table('milestones')->where('id', $milestoneId)->exists()) {
            $milestone['target_date'] = '2027-08-12';
            DB::table('milestones')->insert($milestone);
        }
        if ($sqlite) {
            DB::table('tasks')->where('id', $taskId)->update(['project_id' => $project->getKey()]);
        }
    } finally {
        if ($sqlite) {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    $archiveCountBeforeResume = DB::table('planning_relationship_migration_archive')->count();
    $migration->up();

    expect(DB::table('planning_relationship_migration_archive')->count())->toBe($archiveCountBeforeResume)
        ->and(Schema::hasColumn('projects', 'initiative_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'due_at'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'initiative_id'))->toBeTrue()
        ->and(DB::table('milestones')->where('id', $milestoneId)->value('target_date'))->toBe('2027-08-12')
        ->and(DB::table('tasks')->where('id', $taskId)->value('project_id'))->toBe($project->getKey())
        ->and(Schema::hasIndex('milestones', 'milestones_enterprise_id_idx'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('milestones'))->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['enterprise_id']))->toBeTrue();

    $migration->down();
});

it('resumes the known partial state when the archive contains no relationship records', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    Schema::create('planning_relationship_migration_archive', function (Blueprint $table): void {
        $table->id();
        $table->string('entity_type');
        $table->unsignedBigInteger('entity_id');
        $table->unsignedBigInteger('related_id')->nullable();
        $table->longText('payload')->nullable();
        $table->timestamps();
        $table->index(['entity_type', 'entity_id'], 'planning_archive_entity_entity_idx');
    });

    Schema::table('initiatives', function (Blueprint $table): void {
        $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
    });
    Schema::table('initiatives', function (Blueprint $table): void {
        $table->foreignId('enterprise_id')->nullable(false)->change();
        $table->dropForeign(['strategy_id']);
        $table->foreignId('strategy_id')->nullable()->change();
        $table->foreign('strategy_id')->references('id')->on('strategies')->nullOnDelete();
    });
    Schema::table('projects', function (Blueprint $table): void {
        $table->dropConstrainedForeignId('initiative_id');
    });
    Schema::table('milestones', function (Blueprint $table): void {
        $table->date('target_date')->nullable()->after('description');
    });

    $migration->up();

    expect(DB::table('planning_relationship_migration_archive')->count())->toBe(0)
        ->and(Schema::hasColumn('projects', 'initiative_id'))->toBeFalse()
        ->and(Schema::hasColumn('milestones', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'initiative_id'))->toBeTrue()
        ->and(Schema::hasIndex('milestones', 'milestones_enterprise_id_idx'))->toBeTrue();

    $migration->down();
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

it('recovers an empty archive table left before its index was created', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    Schema::create('planning_relationship_migration_archive', function (Blueprint $table): void {
        $table->id();
        $table->string('entity_type');
        $table->unsignedBigInteger('entity_id');
        $table->unsignedBigInteger('related_id')->nullable();
        $table->longText('payload')->nullable();
        $table->timestamps();
    });

    $migration->up();

    expect(strlen('planning_archive_entity_entity_idx'))->toBeLessThanOrEqual(64)
        ->and(Schema::hasIndex('planning_relationship_migration_archive', 'planning_archive_entity_entity_idx'))->toBeTrue()
        ->and(Schema::hasColumn('initiatives', 'enterprise_id'))->toBeTrue()
        ->and(DB::table('planning_relationship_migration_archive')->count())->toBe(0);

    $migration->down();
});

it('refuses to recover a non-empty archive table without discarding its records', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    Schema::create('planning_relationship_migration_archive', function (Blueprint $table): void {
        $table->id();
        $table->string('entity_type');
        $table->unsignedBigInteger('entity_id');
        $table->unsignedBigInteger('related_id')->nullable();
        $table->longText('payload')->nullable();
        $table->timestamps();
    });
    DB::table('planning_relationship_migration_archive')->insert([
        'entity_type' => 'unknown',
        'entity_id' => 1,
        'related_id' => null,
        'payload' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'archive is non-empty')
        ->and(DB::table('planning_relationship_migration_archive')->count())->toBe(1)
        ->and(Schema::hasColumn('initiatives', 'enterprise_id'))->toBeFalse();

    Schema::drop('planning_relationship_migration_archive');
});

it('refuses to recover an empty archive table after planning schema changes have begun', function () {
    $migration = require database_path('migrations/2026_10_31_000002_restructure_enterprise_planning_relationships.php');
    $migration->down();

    Schema::create('planning_relationship_migration_archive', function (Blueprint $table): void {
        $table->id();
        $table->string('entity_type');
        $table->unsignedBigInteger('entity_id');
        $table->unsignedBigInteger('related_id')->nullable();
        $table->longText('payload')->nullable();
        $table->timestamps();
    });
    Schema::table('initiatives', function (Blueprint $table): void {
        $table->unsignedBigInteger('enterprise_id')->nullable();
    });

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'schema changes had begun')
        ->and(DB::table('planning_relationship_migration_archive')->count())->toBe(0);

    Schema::table('initiatives', function (Blueprint $table): void {
        $table->dropColumn('enterprise_id');
    });
    Schema::drop('planning_relationship_migration_archive');
});
