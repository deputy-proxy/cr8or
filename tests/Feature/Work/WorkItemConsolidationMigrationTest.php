<?php

use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function prepareLegacyWorkItemSchema(): void
{
    Schema::create('work_items', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
        $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('status')->default('todo');
        $table->timestamps();
    });

    Schema::table('workflows', function (Blueprint $table): void {
        $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
    });
    Schema::table('decisions', function (Blueprint $table): void {
        $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
    });
}

function runWorkItemConsolidationMigration(): void
{
    $migration = require database_path('migrations/2026_10_12_000000_consolidate_work_items_into_tasks.php');
    $migration->up();
}

it('maps colliding WorkItem and Task IDs and preserves dependent records', function () {
    $enterprise = Enterprise::factory()->create();
    $project = Project::factory()->create(['enterprise_id' => $enterprise->id]);
    $existingTask = Task::factory()->forProject($project)->create([
        'id' => 1,
        'name' => 'Existing task',
    ]);

    prepareLegacyWorkItemSchema();

    DB::table('work_items')->insert([
        'id' => 1,
        'enterprise_id' => $enterprise->id,
        'project_id' => $project->id,
        'name' => 'Migrated task',
        'description' => 'Preserve this description',
        'status' => 'in_progress',
        'created_at' => '2026-01-02 03:04:05',
        'updated_at' => '2026-01-03 04:05:06',
    ]);

    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise->id]);
    DB::table('workflows')->where('id', $workflow->id)->update(['work_item_id' => 1]);

    $actor = User::factory()->create();
    $decision = Decision::factory()->create([
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
        'title' => 'Historical decision',
        'summary' => 'Keep this exact historical summary.',
        'decided_at' => '2026-02-03 04:05:06',
    ]);
    DB::table('decisions')->where('id', $decision->id)->update(['work_item_id' => 1]);

    DB::table('assignments')->insert([
        'enterprise_id' => $enterprise->id,
        'assignable_type' => 'App\\Models\\WorkItem',
        'assignable_id' => 1,
        'user_id' => $actor->id,
        'agent_assignment_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('dependencies')->insert([
        'enterprise_id' => $enterprise->id,
        'project_id' => $project->id,
        'predecessor_type' => 'App\\Models\\WorkItem',
        'predecessor_id' => 1,
        'successor_type' => Task::class,
        'successor_id' => $existingTask->id,
        'type' => 'blocks',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runWorkItemConsolidationMigration();

    $migratedTask = Task::query()->where('name', 'Migrated task')->firstOrFail();
    expect($migratedTask->id)->not->toBe(1)
        ->and($migratedTask->enterprise_id)->toBe($enterprise->id)
        ->and($migratedTask->project_id)->toBe($project->id)
        ->and($migratedTask->description)->toBe('Preserve this description')
        ->and($migratedTask->status)->toBe('in_progress')
        ->and((string) $migratedTask->created_at)->toContain('2026-01-02')
        ->and(Schema::hasTable('work_items'))->toBeFalse()
        ->and(Schema::hasColumn('workflows', 'work_item_id'))->toBeFalse()
        ->and(Schema::hasColumn('decisions', 'work_item_id'))->toBeFalse();

    expect(DB::table('workflows')->where('id', $workflow->id)->value('task_id'))->toBe($migratedTask->id)
        ->and(DB::table('decisions')->where('id', $decision->id)->value('task_id'))->toBe($migratedTask->id)
        ->and(DB::table('decisions')->where('id', $decision->id)->value('summary'))->toBe('Keep this exact historical summary.')
        ->and(DB::table('decisions')->where('id', $decision->id)->value('decided_at'))->toBe('2026-02-03 04:05:06')
        ->and(DB::table('assignments')->where('user_id', $actor->id)->value('assignable_type'))->toBe(Task::class)
        ->and(DB::table('assignments')->where('user_id', $actor->id)->value('assignable_id'))->toBe($migratedTask->id)
        ->and(DB::table('dependencies')->value('predecessor_type'))->toBe(Task::class)
        ->and(DB::table('dependencies')->value('predecessor_id'))->toBe($migratedTask->id);
});

it('fails before mutation when a Workflow references both a Task and a WorkItem', function () {
    $enterprise = Enterprise::factory()->create();
    $existingTask = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    prepareLegacyWorkItemSchema();

    DB::table('work_items')->insert([
        'enterprise_id' => $enterprise->id,
        'project_id' => null,
        'name' => 'Ambiguous task',
        'description' => null,
        'status' => 'todo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->id,
        'task_id' => $existingTask->id,
    ]);
    DB::table('workflows')->where('id', $workflow->id)->update(['work_item_id' => 1]);

    expect(fn () => runWorkItemConsolidationMigration())->toThrow(RuntimeException::class, 'references both a Task and a WorkItem');
    expect(Schema::hasTable('work_items'))->toBeTrue()
        ->and(Task::query()->count())->toBe(1)
        ->and(DB::table('workflows')->where('id', $workflow->id)->value('task_id'))->toBe($existingTask->id);
});

it('fails before mutation when a WorkItem project crosses Enterprise scope', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignProject = Project::factory()->create(['enterprise_id' => $foreignEnterprise->id]);

    prepareLegacyWorkItemSchema();

    DB::table('work_items')->insert([
        'enterprise_id' => $enterprise->id,
        'project_id' => $foreignProject->id,
        'name' => 'Cross-enterprise WorkItem',
        'description' => null,
        'status' => 'todo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => runWorkItemConsolidationMigration())
        ->toThrow(RuntimeException::class, 'invalid Project/Enterprise scope');

    expect(Schema::hasTable('work_items'))->toBeTrue()
        ->and(Task::query()->count())->toBe(0);
});

it('fails before mutation when a Decision references both a Task and a WorkItem', function () {
    $enterprise = Enterprise::factory()->create();
    $task = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $decision = Decision::factory()->create([
        'enterprise_id' => $enterprise->id,
        'task_id' => $task->id,
    ]);

    prepareLegacyWorkItemSchema();

    DB::table('work_items')->insert([
        'enterprise_id' => $enterprise->id,
        'project_id' => null,
        'name' => 'Ambiguous decision context',
        'description' => null,
        'status' => 'todo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('decisions')->where('id', $decision->id)->update(['work_item_id' => 1]);

    expect(fn () => runWorkItemConsolidationMigration())
        ->toThrow(RuntimeException::class, 'references both a Task and a WorkItem');

    expect(Schema::hasTable('work_items'))->toBeTrue()
        ->and(Task::query()->count())->toBe(1)
        ->and(DB::table('decisions')->where('id', $decision->id)->value('task_id'))->toBe($task->id);
});

it('resumes safely when data references were remapped but legacy schema contraction was interrupted', function () {
    $enterprise = Enterprise::factory()->create();
    $existingTask = Task::factory()->create([
        'id' => 1,
        'enterprise_id' => $enterprise->id,
        'name' => 'Existing Task',
    ]);
    $mappedTask = Task::factory()->create([
        'id' => 2,
        'enterprise_id' => $enterprise->id,
        'name' => 'Previously migrated Task',
    ]);

    prepareLegacyWorkItemSchema();

    DB::table('work_items')->insert([
        'id' => 1,
        'enterprise_id' => $enterprise->id,
        'project_id' => null,
        'name' => 'Legacy WorkItem',
        'description' => null,
        'status' => 'todo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Schema::create('work_item_task_migration_map', function (Blueprint $table): void {
        $table->unsignedBigInteger('work_item_id')->primary();
        $table->unsignedBigInteger('task_id')->unique();
    });
    DB::table('work_item_task_migration_map')->insert([
        'work_item_id' => 1,
        'task_id' => $mappedTask->id,
    ]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->id,
        'task_id' => $mappedTask->id,
    ]);
    DB::table('workflows')->where('id', $workflow->id)->update(['work_item_id' => 1]);

    runWorkItemConsolidationMigration();

    expect(Schema::hasTable('work_items'))->toBeFalse()
        ->and(Schema::hasTable('work_item_task_migration_map'))->toBeFalse()
        ->and(Task::query()->count())->toBe(2)
        ->and(DB::table('workflows')->where('id', $workflow->id)->value('task_id'))->toBe($mappedTask->id)
        ->and($existingTask->refresh()->name)->toBe('Existing Task');
});

it('preserves the legacy execution name snapshot when no Task snapshot exists', function () {
    $enterprise = Enterprise::factory()->create();
    prepareLegacyWorkItemSchema();

    Schema::create('executions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
        $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
        $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
        $table->string('task_name')->nullable();
        $table->string('work_item_name')->nullable();
        $table->timestamps();
    });

    DB::table('work_items')->insert([
        'id' => 1,
        'enterprise_id' => $enterprise->id,
        'project_id' => null,
        'name' => 'Historical WorkItem',
        'description' => null,
        'status' => 'todo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('executions')->insert([
        'enterprise_id' => $enterprise->id,
        'work_item_id' => 1,
        'task_name' => null,
        'work_item_name' => 'Historical WorkItem',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runWorkItemConsolidationMigration();

    $execution = DB::table('executions')->first();
    expect($execution->task_id)->not->toBeNull()
        ->and($execution->task_name)->toBe('Historical WorkItem')
        ->and(Schema::hasColumn('executions', 'work_item_id'))->toBeFalse()
        ->and(Schema::hasColumn('executions', 'work_item_name'))->toBeFalse();
});

it('fails before mutation when execution name snapshots conflict', function () {
    $enterprise = Enterprise::factory()->create();
    prepareLegacyWorkItemSchema();

    Schema::create('executions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
        $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
        $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
        $table->string('task_name')->nullable();
        $table->string('work_item_name')->nullable();
        $table->timestamps();
    });

    DB::table('executions')->insert([
        'enterprise_id' => $enterprise->id,
        'task_name' => 'Historical Task name',
        'work_item_name' => 'Historical WorkItem name',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => runWorkItemConsolidationMigration())
        ->toThrow(RuntimeException::class, 'contain different Task and WorkItem name snapshots');

    expect(Schema::hasTable('work_items'))->toBeTrue()
        ->and(Task::query()->count())->toBe(0)
        ->and(Schema::hasColumn('executions', 'work_item_name'))->toBeTrue();
});

it('fails before reference remapping when consolidation would duplicate an assignment', function () {
    $enterprise = Enterprise::factory()->create();
    $existingTask = Task::factory()->create(['enterprise_id' => $enterprise->id]);
    $user = User::factory()->create();
    prepareLegacyWorkItemSchema();

    DB::table('work_items')->insert([
        'id' => 1,
        'enterprise_id' => $enterprise->id,
        'project_id' => null,
        'name' => 'Assigned WorkItem',
        'description' => null,
        'status' => 'todo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('assignments')->insert([
        [
            'enterprise_id' => $enterprise->id,
            'assignable_type' => Task::class,
            'assignable_id' => $existingTask->id,
            'user_id' => $user->id,
            'agent_assignment_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'enterprise_id' => $enterprise->id,
            'assignable_type' => 'App\\Models\\WorkItem',
            'assignable_id' => 1,
            'user_id' => $user->id,
            'agent_assignment_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Force the WorkItem to map to the existing Task to exercise a real collision.
    Schema::create('work_item_task_migration_map', function (Blueprint $table): void {
        $table->unsignedBigInteger('work_item_id')->primary();
        $table->unsignedBigInteger('task_id')->unique();
    });
    DB::table('work_item_task_migration_map')->insert(['work_item_id' => 1, 'task_id' => $existingTask->id]);

    expect(fn () => runWorkItemConsolidationMigration())
        ->toThrow(RuntimeException::class, 'would duplicate assignments');

    expect(Schema::hasTable('work_items'))->toBeTrue()
        ->and(DB::table('assignments')->where('assignable_type', 'App\\Models\\WorkItem')->count())->toBe(1)
        ->and(DB::table('assignments')->where('assignable_type', Task::class)->count())->toBe(1);
});
