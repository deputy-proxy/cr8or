<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        try {
            $this->applyUp();
            if ($sqlite && DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Planning relationship migration produced invalid SQLite foreign key references.');
            }
        } finally {
            if ($sqlite) {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function applyUp(): void
    {
        $this->validateLegacyData();

        if (Schema::hasTable('planning_relationship_migration_archive')) {
            throw new RuntimeException('A previous planning relationship migration attempt left an archive table. Inspect and reconcile that attempt before rerunning.');
        }

        Schema::create('planning_relationship_migration_archive', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('related_id')->nullable();
            $table->longText('payload')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });

        foreach (DB::table('projects')->whereNotNull('initiative_id')->orderBy('id')->get(['id', 'enterprise_id', 'initiative_id']) as $project) {
            DB::table('planning_relationship_migration_archive')->insert([
                'entity_type' => 'project_initiative',
                'entity_id' => $project->id,
                'related_id' => $project->initiative_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $initiativeEnterpriseId = DB::table('initiatives')
                ->join('strategies', 'strategies.id', '=', 'initiatives.strategy_id')
                ->join('objectives', 'objectives.id', '=', 'strategies.objective_id')
                ->where('initiatives.id', $project->initiative_id)
                ->value('objectives.enterprise_id');
            if ($initiativeEnterpriseId === null || (int) $initiativeEnterpriseId !== (int) $project->enterprise_id) {
                throw new RuntimeException("Project {$project->id} has an Initiative reference outside its Enterprise. Resolve this record before removing Project/Initiative relationships.");
            }
        }

        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        foreach (DB::table('initiatives')->orderBy('id')->get(['id', 'strategy_id']) as $initiative) {
            $enterpriseId = DB::table('strategies')
                ->join('objectives', 'objectives.id', '=', 'strategies.objective_id')
                ->where('strategies.id', $initiative->strategy_id)
                ->value('objectives.enterprise_id');

            if ($enterpriseId === null) {
                throw new RuntimeException("Cannot make Initiative {$initiative->id} Enterprise-owned: its Strategy has no resolvable Enterprise.");
            }

            DB::table('initiatives')->where('id', $initiative->id)->update(['enterprise_id' => $enterpriseId]);
        }

        if (DB::table('initiatives')->whereNull('enterprise_id')->exists()) {
            throw new RuntimeException('Cannot make Initiatives Enterprise-owned: one or more records have no resolvable Enterprise.');
        }

        Schema::table('initiatives', function (Blueprint $table): void {
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->dropForeign(['strategy_id']);
            $table->foreignId('strategy_id')->nullable()->change();
            $table->foreign('strategy_id')->references('id')->on('strategies')->nullOnDelete();
        });

        foreach (DB::table('milestones')->orderBy('id')->get() as $milestone) {
            DB::table('planning_relationship_migration_archive')->insert([
                'entity_type' => 'milestone_record',
                'entity_id' => $milestone->id,
                'related_id' => null,
                'payload' => json_encode((array) $milestone, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('planning_relationship_migration_archive')->insert([
                'entity_type' => 'milestone_project',
                'entity_id' => $milestone->id,
                'related_id' => $milestone->project_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $projectEnterpriseId = DB::table('projects')->where('id', $milestone->project_id)->value('enterprise_id');
            if ($projectEnterpriseId === null || (int) $projectEnterpriseId !== (int) $milestone->enterprise_id) {
                throw new RuntimeException("Milestone {$milestone->id} has an invalid Project/Enterprise reference. Resolve this record before migration.");
            }
        }

        Schema::table('milestones', function (Blueprint $table): void {
            $table->date('target_date')->nullable()->after('description');
        });

        DB::table('milestones')->update(['target_date' => DB::raw('DATE(due_at)')]);

        foreach (DB::table('tasks')->whereNotNull('project_id')->orderBy('id')->get(['id', 'project_id']) as $task) {
            DB::table('planning_relationship_migration_archive')->insert([
                'entity_type' => 'task_project',
                'entity_id' => $task->id,
                'related_id' => $task->project_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initiative_id');
        });

        Schema::table('milestones', function (Blueprint $table): void {
            $table->dropIndex(['enterprise_id', 'project_id']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('due_at');
        });

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_record')->get() as $archive) {
            $milestone = json_decode($archive->payload, true, 512, JSON_THROW_ON_ERROR);
            $targetDate = $milestone['due_at'] === null ? null : substr((string) $milestone['due_at'], 0, 10);
            unset($milestone['project_id'], $milestone['due_at']);
            $milestone['target_date'] = $targetDate;
            DB::table('milestones')->updateOrInsert(['id' => $archive->entity_id], $milestone);
        }

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'task_project')->get() as $archive) {
            DB::table('tasks')->where('id', $archive->entity_id)->update(['project_id' => $archive->related_id]);
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('initiative_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        foreach (DB::table('tasks')->orderBy('id')->get(['id', 'enterprise_id', 'project_id', 'initiative_id', 'parent_task_id']) as $task) {
            $hasProject = $task->project_id !== null;
            $hasInitiative = $task->initiative_id !== null;

            if ($hasProject && $hasInitiative) {
                throw new RuntimeException("Task {$task->id} cannot belong to both a Project and Initiative.");
            }

            $parentEnterpriseId = match (true) {
                $hasProject => DB::table('projects')->where('id', $task->project_id)->value('enterprise_id'),
                $hasInitiative => DB::table('initiatives')->where('id', $task->initiative_id)->value('enterprise_id'),
                default => null,
            };

            if (($hasProject || $hasInitiative) && ($parentEnterpriseId === null || (int) $parentEnterpriseId !== (int) $task->enterprise_id)) {
                throw new RuntimeException("Task {$task->id} has a Project or Initiative outside its Enterprise.");
            }

            if ($task->parent_task_id !== null) {
                $parent = DB::table('tasks')->where('id', $task->parent_task_id)->first(['id', 'enterprise_id', 'project_id', 'initiative_id']);
                if ($parent === null
                    || (int) $parent->enterprise_id !== (int) $task->enterprise_id
                    || $parent->project_id !== $task->project_id
                    || $parent->initiative_id !== $task->initiative_id) {
                    throw new RuntimeException("Task {$task->id} has a Parent Task outside its Enterprise and Project or Initiative scope.");
                }
            }
        }

        $this->addTaskParentConstraint();
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        try {
            $this->applyDown();
            if ($sqlite && DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Planning relationship rollback produced invalid SQLite foreign key references.');
            }
        } finally {
            if ($sqlite) {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function applyDown(): void
    {
        if (DB::table('initiatives')->whereNull('strategy_id')->exists()) {
            throw new RuntimeException('Cannot roll back planning relationships while an Initiative has no Strategy. Assign a Strategy before rollback.');
        }

        if (DB::table('milestones')->whereNotIn('id', DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_project')->select('entity_id'))->exists()) {
            throw new RuntimeException('Cannot roll back planning relationships while a Milestone has no archived Project association.');
        }

        if (DB::table('tasks')->whereNotNull('initiative_id')->exists()) {
            throw new RuntimeException('Cannot roll back planning relationships while a Task has a new Initiative association that did not exist before this migration.');
        }

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE tasks DROP CHECK tasks_at_most_one_parent');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_at_most_one_parent');
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initiative_id');
        });

        foreach (DB::table('milestones')->orderBy('id')->get() as $milestone) {
            DB::table('planning_relationship_migration_archive')->insert([
                'entity_type' => 'milestone_rollback',
                'entity_id' => $milestone->id,
                'related_id' => null,
                'payload' => json_encode((array) $milestone, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('tasks')->whereNotNull('project_id')->orderBy('id')->get(['id', 'project_id']) as $task) {
            DB::table('planning_relationship_migration_archive')->insert([
                'entity_type' => 'task_project_rollback',
                'entity_id' => $task->id,
                'related_id' => $task->project_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('initiative_id')->nullable()->after('strategy_id')->constrained()->nullOnDelete();
        });

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'task_project_rollback')->get() as $archive) {
            DB::table('tasks')->where('id', $archive->entity_id)->update(['project_id' => $archive->related_id]);
        }

        Schema::table('milestones', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('enterprise_id')->constrained()->cascadeOnDelete();
            $table->timestamp('due_at')->nullable()->after('description');
        });

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_rollback')->get() as $archive) {
            $current = json_decode($archive->payload, true, 512, JSON_THROW_ON_ERROR);
            $originalPayload = DB::table('planning_relationship_migration_archive')
                ->where('entity_type', 'milestone_record')
                ->where('entity_id', $archive->entity_id)
                ->value('payload');
            $original = $originalPayload === null ? [] : json_decode($originalPayload, true, 512, JSON_THROW_ON_ERROR);
            $originalDueAt = $original['due_at'] ?? null;
            $originalTargetDate = $originalDueAt === null ? null : substr((string) $originalDueAt, 0, 10);
            $currentTargetDate = $current['target_date'] ?? null;
            $current['due_at'] = $currentTargetDate === $originalTargetDate
                ? $originalDueAt
                : ($currentTargetDate === null ? null : $currentTargetDate.' 00:00:00');
            $current['project_id'] = DB::table('planning_relationship_migration_archive')
                ->where('entity_type', 'milestone_project')
                ->where('entity_id', $archive->entity_id)
                ->value('related_id');
            unset($current['target_date']);

            DB::table('milestones')->updateOrInsert(['id' => $archive->entity_id], $current);
        }

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_project')->get() as $link) {
            DB::table('milestones')->where('id', $link->entity_id)->update(['project_id' => $link->related_id]);
        }

        if (DB::table('milestones')->whereNull('project_id')->exists()) {
            throw new RuntimeException('Cannot restore the original required Milestone Project association.');
        }

        Schema::table('milestones', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->dropColumn('target_date');
            $table->index(['enterprise_id', 'project_id']);
        });

        Schema::table('initiatives', function (Blueprint $table): void {
            $table->dropForeign(['strategy_id']);
            $table->foreignId('strategy_id')->nullable(false)->change();
            $table->foreign('strategy_id')->references('id')->on('strategies')->cascadeOnDelete();
            $table->dropConstrainedForeignId('enterprise_id');
        });

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'project_initiative')->get() as $link) {
            DB::table('projects')->where('id', $link->entity_id)->update(['initiative_id' => $link->related_id]);
        }

        Schema::dropIfExists('planning_relationship_migration_archive');
    }

    private function validateLegacyData(): void
    {
        foreach (DB::table('initiatives')->orderBy('id')->get(['id', 'strategy_id']) as $initiative) {
            $enterpriseId = DB::table('strategies')
                ->join('objectives', 'objectives.id', '=', 'strategies.objective_id')
                ->where('strategies.id', $initiative->strategy_id)
                ->value('objectives.enterprise_id');

            if ($enterpriseId === null) {
                throw new RuntimeException("Cannot make Initiative {$initiative->id} Enterprise-owned: its Strategy has no resolvable Enterprise.");
            }
        }

        foreach (DB::table('projects')->whereNotNull('initiative_id')->orderBy('id')->get(['id', 'enterprise_id', 'initiative_id']) as $project) {
            $initiativeEnterpriseId = DB::table('initiatives')
                ->join('strategies', 'strategies.id', '=', 'initiatives.strategy_id')
                ->join('objectives', 'objectives.id', '=', 'strategies.objective_id')
                ->where('initiatives.id', $project->initiative_id)
                ->value('objectives.enterprise_id');

            if ($initiativeEnterpriseId === null || (int) $initiativeEnterpriseId !== (int) $project->enterprise_id) {
                throw new RuntimeException("Project {$project->id} has an Initiative reference outside its Enterprise. Resolve this record before removing Project/Initiative relationships.");
            }
        }

        foreach (DB::table('milestones')->orderBy('id')->get(['id', 'enterprise_id', 'project_id']) as $milestone) {
            $projectEnterpriseId = DB::table('projects')->where('id', $milestone->project_id)->value('enterprise_id');

            if ($projectEnterpriseId === null || (int) $projectEnterpriseId !== (int) $milestone->enterprise_id) {
                throw new RuntimeException("Milestone {$milestone->id} has an invalid Project/Enterprise reference. Resolve this record before migration.");
            }
        }

        foreach (DB::table('tasks')->orderBy('id')->get(['id', 'enterprise_id', 'project_id', 'parent_task_id']) as $task) {
            if ($task->project_id !== null) {
                $projectEnterpriseId = DB::table('projects')->where('id', $task->project_id)->value('enterprise_id');
                if ($projectEnterpriseId === null || (int) $projectEnterpriseId !== (int) $task->enterprise_id) {
                    throw new RuntimeException("Task {$task->id} has a Project outside its Enterprise.");
                }
            }

            if ($task->parent_task_id !== null) {
                $parent = DB::table('tasks')->where('id', $task->parent_task_id)->first(['id', 'enterprise_id', 'project_id']);
                if ($parent === null
                    || (int) $parent->enterprise_id !== (int) $task->enterprise_id
                    || $parent->project_id !== $task->project_id) {
                    throw new RuntimeException("Task {$task->id} has a Parent Task outside its Enterprise and Project scope.");
                }
            }
        }
    }

    private function addTaskParentConstraint(): void
    {
        $driver = DB::getDriverName();
        $condition = '(project_id IS NULL OR initiative_id IS NULL)';

        if ($driver === 'sqlite') {
            // SQLite cannot add a CHECK constraint without rebuilding the table. Model and service
            // invariants remain enforced here; avoid triggers because later SQLite table rebuilds
            // can leave trigger definitions bound to temporary tables.
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_at_most_one_parent CHECK ({$condition})");
        }
    }
};
