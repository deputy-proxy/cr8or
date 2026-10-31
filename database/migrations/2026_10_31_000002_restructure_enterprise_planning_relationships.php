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
        if (Schema::hasTable('planning_relationship_migration_archive')
            && (DB::table('planning_relationship_migration_archive')->exists() || $this->hasMilestoneContractionPrefix())) {
            $this->assertRecoverableMilestoneContractionState();
            $this->continueUpAfterMilestoneContraction();

            return;
        }

        $this->validateLegacyData();

        $this->prepareArchiveTable();

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

        $this->continueUpAfterMilestoneContraction();
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

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $checkExists = DB::table('information_schema.table_constraints')
                ->where('constraint_schema', DB::getDatabaseName())
                ->where('table_name', 'tasks')
                ->where('constraint_name', 'tasks_at_most_one_parent')
                ->where('constraint_type', 'CHECK')
                ->exists();

            if ($checkExists) {
                DB::statement('ALTER TABLE tasks DROP CHECK tasks_at_most_one_parent');
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_at_most_one_parent');
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

        if (Schema::hasIndex('milestones', 'milestones_enterprise_id_idx')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropIndex('milestones_enterprise_id_idx');
            });
        }

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

    private function continueUpAfterMilestoneContraction(): void
    {
        $this->removeMilestoneProjectRelationship();

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

        if (! Schema::hasColumn('tasks', 'initiative_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->foreignId('initiative_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            });
        }

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

    /**
     * Remove the legacy Milestone/Project relationship without dropping the index
     * that InnoDB may be using to support the Enterprise foreign key.
     */
    private function removeMilestoneProjectRelationship(): void
    {
        $enterpriseIndexExists = collect(Schema::getIndexes('milestones'))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['enterprise_id'],
        );

        if (! $enterpriseIndexExists) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->index('enterprise_id', 'milestones_enterprise_id_idx');
            });
        }

        if ($this->hasForeignKeyOnColumns('milestones', ['project_id'])) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropForeign(['project_id']);
            });
        }

        if (Schema::hasIndex('milestones', ['enterprise_id', 'project_id'])) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropIndex(['enterprise_id', 'project_id']);
            });
        }

        if (Schema::hasColumn('milestones', 'project_id')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropColumn('project_id');
            });
        }

        if (Schema::hasColumn('milestones', 'due_at')) {
            Schema::table('milestones', function (Blueprint $table): void {
                $table->dropColumn('due_at');
            });
        }

        if (! $this->hasForeignKeyOnColumns('milestones', ['enterprise_id'])) {
            throw new RuntimeException('Milestone Enterprise foreign key was not preserved while removing the legacy Project relationship.');
        }

        $enterpriseIndexExists = collect(Schema::getIndexes('milestones'))->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['enterprise_id'],
        );

        if (! $enterpriseIndexExists) {
            throw new RuntimeException('Milestones must retain a dedicated Enterprise index after removing the legacy composite index.');
        }
    }

    /** @param list<string> $columns */
    private function hasForeignKeyOnColumns(string $table, array $columns): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === $columns,
        );
    }

    private function hasMilestoneContractionPrefix(): bool
    {
        return Schema::hasColumn('initiatives', 'enterprise_id')
            && ! Schema::hasColumn('projects', 'initiative_id')
            && Schema::hasColumn('milestones', 'target_date')
            && Schema::hasColumn('tasks', 'project_id');
    }

    /**
     * Validate the known non-transactional DDL interruption point before resuming.
     */
    private function assertRecoverableMilestoneContractionState(): void
    {
        $expectedColumns = [
            'initiatives' => ['enterprise_id', 'strategy_id'],
            'projects' => ['enterprise_id'],
            'milestones' => ['enterprise_id', 'target_date'],
            'tasks' => ['enterprise_id', 'project_id'],
        ];

        foreach ($expectedColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("The planning migration archive is non-empty, but the interrupted migration state is not recognized: {$table}.{$column} is missing.");
                }
            }
        }

        if (Schema::hasColumn('projects', 'initiative_id')
            || DB::table('initiatives')->whereNull('enterprise_id')->exists()) {
            throw new RuntimeException('The planning migration archive is non-empty, but the schema does not match the known milestone-contraction interruption point. Refusing automatic recovery.');
        }

        if (! $this->hasForeignKeyOnColumns('milestones', ['enterprise_id'])
            || ! $this->hasForeignKeyOnColumns('initiatives', ['enterprise_id'])
            || ! $this->hasForeignKeyOnColumns('initiatives', ['strategy_id'])
            || (Schema::hasColumn('tasks', 'initiative_id') && ! $this->hasForeignKeyOnColumns('tasks', ['initiative_id']))) {
            throw new RuntimeException('The interrupted planning migration state is missing an expected Initiative or Milestone foreign key. Refusing automatic recovery.');
        }

        $initiativeColumns = collect(Schema::getColumns('initiatives'))->keyBy('name');
        if (($initiativeColumns->get('enterprise_id')['nullable'] ?? true)
            || ! ($initiativeColumns->get('strategy_id')['nullable'] ?? false)) {
            throw new RuntimeException('The interrupted planning migration state has unexpected Initiative nullability. Refusing automatic recovery.');
        }

        if (Schema::hasColumn('milestones', 'project_id') && ! Schema::hasColumn('milestones', 'due_at')) {
            throw new RuntimeException('The interrupted planning migration state has an unexpected Milestone column combination. Refusing automatic recovery.');
        }

        $allowedTypes = ['project_initiative', 'milestone_record', 'milestone_project', 'task_project'];
        $actualTypes = DB::table('planning_relationship_migration_archive')->distinct()->pluck('entity_type')->all();
        $unexpectedTypes = array_values(array_diff($actualTypes, $allowedTypes));

        if ($unexpectedTypes !== []) {
            throw new RuntimeException('The planning migration archive contains unexpected record types: '.implode(', ', $unexpectedTypes).'. Refusing automatic recovery.');
        }

        foreach ($allowedTypes as $entityType) {
            $records = DB::table('planning_relationship_migration_archive')->where('entity_type', $entityType)->get(['entity_id']);
            if ($records->pluck('entity_id')->unique()->count() !== $records->count()) {
                throw new RuntimeException("The planning migration archive contains duplicate {$entityType} records. Refusing automatic recovery.");
            }
        }

        $milestoneRecords = DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_record')->get();
        $milestoneProjects = DB::table('planning_relationship_migration_archive')->where('entity_type', 'milestone_project')->get();
        if ($milestoneRecords->count() !== $milestoneProjects->count()
            || $milestoneRecords->count() !== DB::table('milestones')->count()
            || $milestoneProjects->pluck('entity_id')->unique()->count() !== $milestoneProjects->count()) {
            throw new RuntimeException('The planning migration archive does not contain exactly one original record and Project association for every Milestone. Refusing automatic recovery.');
        }

        $milestoneProjectById = $milestoneProjects->keyBy('entity_id');
        foreach ($milestoneRecords as $archive) {
            if (! is_string($archive->payload) || $archive->payload === '') {
                throw new RuntimeException("The planning migration archive is missing the original Milestone payload for Milestone {$archive->entity_id}. Refusing automatic recovery.");
            }

            $payload = json_decode($archive->payload, true, 512, JSON_THROW_ON_ERROR);
            $link = $milestoneProjectById->get($archive->entity_id);
            $current = DB::table('milestones')->where('id', $archive->entity_id)->first(['id', 'enterprise_id']);
            $enterpriseId = (int) ($payload['enterprise_id'] ?? 0);

            if (! is_array($payload)
                || ! array_key_exists('project_id', $payload)
                || ! array_key_exists('enterprise_id', $payload)
                || ! array_key_exists('due_at', $payload)
                || $link === null
                || $current === null
                || (int) ($payload['id'] ?? 0) !== (int) $archive->entity_id
                || (int) ($payload['project_id'] ?? 0) !== (int) $link->related_id
                || $enterpriseId === 0
                || (int) $current->enterprise_id !== $enterpriseId
                || ! DB::table('projects')->where('id', $link->related_id)->where('enterprise_id', $enterpriseId)->exists()) {
                throw new RuntimeException("The planning migration archive contains an invalid Milestone record or Project association for Milestone {$archive->entity_id}. Refusing automatic recovery.");
            }
        }

        $taskProjects = DB::table('planning_relationship_migration_archive')->where('entity_type', 'task_project')->get();
        if ($taskProjects->count() !== DB::table('tasks')->whereNotNull('project_id')->count()) {
            throw new RuntimeException('The planning migration archive does not match the current Task/Project associations. Refusing automatic recovery.');
        }

        foreach ($taskProjects as $archive) {
            $task = DB::table('tasks')->where('id', $archive->entity_id)->first(['enterprise_id', 'project_id']);
            $project = DB::table('projects')->where('id', $archive->related_id)->first(['enterprise_id']);

            if ($task === null
                || $project === null
                || (int) $task->project_id !== (int) $archive->related_id
                || (int) $task->enterprise_id !== (int) $project->enterprise_id) {
                throw new RuntimeException("The planning migration archive contains an invalid Task/Project association for Task {$archive->entity_id}. Refusing automatic recovery.");
            }
        }

        foreach (DB::table('planning_relationship_migration_archive')->where('entity_type', 'project_initiative')->get() as $archive) {
            $project = DB::table('projects')->where('id', $archive->entity_id)->first(['enterprise_id']);
            $initiative = DB::table('initiatives')->where('id', $archive->related_id)->first(['enterprise_id']);

            if ($project === null || $initiative === null || (int) $project->enterprise_id !== (int) $initiative->enterprise_id) {
                throw new RuntimeException("The planning migration archive contains an invalid Project/Initiative association for Project {$archive->entity_id}. Refusing automatic recovery.");
            }
        }
    }

    private function prepareArchiveTable(): void
    {
        $tableName = 'planning_relationship_migration_archive';
        $indexName = 'planning_archive_entity_entity_idx';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($indexName): void {
                $table->id();
                $table->string('entity_type');
                $table->unsignedBigInteger('entity_id');
                $table->unsignedBigInteger('related_id')->nullable();
                $table->longText('payload')->nullable();
                $table->timestamps();
                $table->index(['entity_type', 'entity_id'], $indexName);
            });

            return;
        }

        $requiredColumns = ['id', 'entity_type', 'entity_id', 'related_id', 'payload', 'created_at', 'updated_at'];
        $missingColumns = array_values(array_filter(
            $requiredColumns,
            fn (string $column): bool => ! Schema::hasColumn($tableName, $column),
        ));

        if ($missingColumns !== []) {
            throw new RuntimeException('A previous planning relationship migration attempt left an incompatible archive table. Missing columns: '.implode(', ', $missingColumns).'. Inspect and reconcile that attempt before rerunning.');
        }

        if (DB::table($tableName)->exists()) {
            throw new RuntimeException('A previous planning relationship migration attempt left archive records. Refusing to resume or overwrite a non-empty archive table; inspect and reconcile that attempt before rerunning.');
        }

        $legacySchemaIsIntact = ! Schema::hasColumn('initiatives', 'enterprise_id')
            && Schema::hasColumn('projects', 'initiative_id')
            && Schema::hasColumn('milestones', 'project_id')
            && Schema::hasColumn('milestones', 'due_at')
            && ! Schema::hasColumn('milestones', 'target_date')
            && Schema::hasColumn('tasks', 'project_id')
            && ! Schema::hasColumn('tasks', 'initiative_id');

        if (! $legacySchemaIsIntact) {
            throw new RuntimeException('A previous planning relationship migration attempt left an empty archive table after schema changes had begun. Refusing automatic recovery because the database is not in the original pre-migration state. Inspect and reconcile that attempt before rerunning.');
        }

        if (! Schema::hasIndex($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->index(['entity_type', 'entity_id'], $indexName);
            });
        }
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

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MySQL rejects CHECK expressions that reference columns used by
            // ON DELETE SET NULL foreign keys (error 1901). Triggers are not
            // portable to shared-hosting MySQL installations because binary
            // logging can require SUPER/log_bin_trust_function_creators. The
            // Task model and TaskService enforce this invariant for all
            // application writes without changing existing FK delete behavior.
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite cannot add a CHECK constraint without rebuilding the table.
            // Model and service invariants remain enforced for application writes.
            return;
        }

        if ($driver === 'pgsql') {
            $constraintExists = DB::table('pg_constraint')
                ->where('conname', 'tasks_at_most_one_parent')
                ->where('conrelid', DB::raw("'tasks'::regclass"))
                ->exists();

            if (! $constraintExists) {
                DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_at_most_one_parent CHECK ({$condition})");
            }
        }
    }
};
