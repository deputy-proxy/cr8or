<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('work_items')) {
            Schema::dropIfExists('work_item_task_migration_map');

            return;
        }

        $this->assertUnambiguousReferences();
        $this->assertUnambiguousExecutionSnapshots();
        $this->assertValidScopes();

        if (! Schema::hasTable('work_item_task_migration_map')) {
            Schema::create('work_item_task_migration_map', function (Blueprint $table): void {
                $table->unsignedBigInteger('work_item_id')->primary();
                $table->unsignedBigInteger('task_id')->unique();
            });
        }

        DB::transaction(function (): void {
            DB::table('work_items')->orderBy('id')->chunk(200, function ($items): void {
                foreach ($items as $item) {
                    $mappedTaskId = DB::table('work_item_task_migration_map')
                        ->where('work_item_id', $item->id)
                        ->value('task_id');

                    if ($mappedTaskId !== null) {
                        if (! DB::table('tasks')->where('id', $mappedTaskId)->exists()) {
                            throw new RuntimeException("Migration map for WorkItem {$item->id} points to a missing Task {$mappedTaskId}.");
                        }

                        continue;
                    }

                    $task = Task::query()->create([
                        'enterprise_id' => $item->enterprise_id,
                        'project_id' => $item->project_id,
                        'parent_task_id' => null,
                        'name' => $item->name,
                        'description' => $item->description,
                        'status' => $item->status,
                        'priority' => 'normal',
                        'due_at' => null,
                    ]);

                    DB::table('tasks')->where('id', $task->getKey())->update([
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);

                    DB::table('work_item_task_migration_map')->insert([
                        'work_item_id' => $item->id,
                        'task_id' => $task->getKey(),
                    ]);
                }
            });

            $this->assertNoDependencyCollisions();
            $this->assertNoAssignmentCollisions();
            $this->remapWorkflowAndDecisionReferences();
            $this->remapDependencies();
            $this->remapAssignments();
            $this->remapLegacyExecutions();
        });

        // Keep the mapping table until schema contraction succeeds so a failed DDL step can be retried safely.
        $this->dropLegacyColumns();
        Schema::drop('work_items');
        Schema::dropIfExists('work_item_task_migration_map');
    }

    public function down(): void
    {
        throw new RuntimeException('WorkItem consolidation is destructive and cannot be automatically rolled back. Restore a pre-migration backup to reverse it.');
    }

    private function assertUnambiguousReferences(): void
    {
        foreach (['workflows', 'decisions', 'executions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'work_item_id') || ! Schema::hasColumn($table, 'task_id')) {
                continue;
            }

            foreach (DB::table($table)
                ->whereNotNull('task_id')
                ->whereNotNull('work_item_id')
                ->get(['id', 'task_id', 'work_item_id']) as $record) {
                if (Schema::hasTable('work_item_task_migration_map')) {
                    $mappedTaskId = DB::table('work_item_task_migration_map')
                        ->where('work_item_id', $record->work_item_id)
                        ->value('task_id');

                    if ($mappedTaskId !== null && (int) $mappedTaskId === (int) $record->task_id) {
                        continue;
                    }
                }

                throw new RuntimeException("Cannot consolidate WorkItems: {$table} record {$record->id} references both a Task and a WorkItem. Resolve this ambiguous context before retrying.");
            }
        }
    }

    private function assertUnambiguousExecutionSnapshots(): void
    {
        if (! Schema::hasTable('executions')
            || ! Schema::hasColumn('executions', 'task_name')
            || ! Schema::hasColumn('executions', 'work_item_name')) {
            return;
        }

        $conflicts = DB::table('executions')
            ->whereNotNull('task_name')
            ->whereNotNull('work_item_name')
            ->whereColumn('task_name', '!=', 'work_item_name')
            ->pluck('id');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot consolidate WorkItems: legacy Execution records ['.$conflicts->implode(', ').
                '] contain different Task and WorkItem name snapshots. Preserve both historical names explicitly before retrying.',
            );
        }
    }

    private function assertValidScopes(): void
    {
        foreach (DB::table('work_items')->orderBy('id')->get() as $item) {
            if ($item->project_id !== null) {
                $projectEnterpriseId = DB::table('projects')->where('id', $item->project_id)->value('enterprise_id');
                if ($projectEnterpriseId === null || (int) $projectEnterpriseId !== (int) $item->enterprise_id) {
                    throw new RuntimeException("WorkItem {$item->id} has invalid Project/Enterprise scope; no data was consolidated.");
                }
            }
        }

        foreach (['workflows', 'decisions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'work_item_id')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('work_item_id')->get(['id', 'enterprise_id', 'work_item_id']) as $record) {
                $enterpriseId = DB::table('work_items')->where('id', $record->work_item_id)->value('enterprise_id');
                if ($enterpriseId === null || (int) $enterpriseId !== (int) $record->enterprise_id) {
                    throw new RuntimeException("Cannot consolidate {$table} record {$record->id}: missing WorkItem or cross-Enterprise reference.");
                }
            }
        }

        $workItemType = ('App'.chr(92).'Models'.chr(92).'WorkItem');
        foreach (['assignments' => ['assignable_type', 'assignable_id'], 'dependencies' => ['predecessor_type', 'predecessor_id']] as $table => [$typeColumn, $idColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where($typeColumn, $workItemType)->get(['id', 'enterprise_id', $idColumn]) as $record) {
                $enterpriseId = DB::table('work_items')->where('id', $record->{$idColumn})->value('enterprise_id');
                if ($enterpriseId === null || (int) $enterpriseId !== (int) $record->enterprise_id) {
                    throw new RuntimeException("Cannot consolidate {$table} record {$record->id}: missing WorkItem or cross-Enterprise reference.");
                }
            }
        }
        if (Schema::hasTable('dependencies')) {
            foreach (DB::table('dependencies')->where('successor_type', $workItemType)->get(['id', 'enterprise_id', 'successor_id']) as $record) {
                $enterpriseId = DB::table('work_items')->where('id', $record->successor_id)->value('enterprise_id');
                if ($enterpriseId === null || (int) $enterpriseId !== (int) $record->enterprise_id) {
                    throw new RuntimeException("Cannot consolidate Dependency {$record->id}: missing WorkItem or cross-Enterprise reference.");
                }
            }
        }

        if (Schema::hasTable('dependencies')) {
            foreach (DB::table('dependencies')->orderBy('id')->get() as $dependency) {
                $endpointProjectIds = [];
                foreach ([
                    [$dependency->predecessor_type, $dependency->predecessor_id],
                    [$dependency->successor_type, $dependency->successor_id],
                ] as [$type, $id]) {
                    if ($type === $workItemType) {
                        $endpointProjectIds[] = DB::table('work_items')->where('id', $id)->value('project_id');
                    } elseif ($type === (new Task)->getMorphClass()) {
                        $endpointProjectIds[] = DB::table('tasks')->where('id', $id)->value('project_id');
                    } elseif ($type === (new Project)->getMorphClass()) {
                        $endpointProjectIds[] = (int) $id;
                    } elseif ($type === (new Milestone)->getMorphClass()) {
                        $endpointProjectIds[] = DB::table('milestones')->where('id', $id)->value('project_id');
                    }
                }

                if ($dependency->project_id !== null) {
                    foreach ($endpointProjectIds as $endpointProjectId) {
                        if ($endpointProjectId === null || (int) $endpointProjectId !== (int) $dependency->project_id) {
                            throw new RuntimeException("Dependency {$dependency->id} has invalid Project scope; no data was consolidated.");
                        }
                    }
                }
            }
        }
    }

    private function assertNoDependencyCollisions(): void
    {
        if (! Schema::hasTable('dependencies')) {
            return;
        }
        $workItemType = ('App'.chr(92).'Models'.chr(92).'WorkItem');
        $taskType = (new Task)->getMorphClass();
        $seen = [];
        foreach (DB::table('dependencies')->orderBy('id')->get() as $edge) {
            $predecessorType = $edge->predecessor_type;
            $predecessorId = (int) $edge->predecessor_id;
            $successorType = $edge->successor_type;
            $successorId = (int) $edge->successor_id;
            if ($predecessorType === $workItemType) {
                $predecessorType = $taskType;
                $predecessorId = $this->mappedTaskId($predecessorId);
            }
            if ($successorType === $workItemType) {
                $successorType = $taskType;
                $successorId = $this->mappedTaskId($successorId);
            }
            $key = implode(':', [$edge->enterprise_id, $predecessorType, $predecessorId, $successorType, $successorId, $edge->type]);
            if (isset($seen[$key])) {
                throw new RuntimeException("Consolidation would duplicate dependency edges {$seen[$key]} and {$edge->id}; resolve the collision before retrying.");
            }
            $seen[$key] = $edge->id;
        }
    }

    private function assertNoAssignmentCollisions(): void
    {
        if (! Schema::hasTable('assignments')) {
            return;
        }

        $workItemType = ('App'.chr(92).'Models'.chr(92).'WorkItem');
        $taskType = (new Task)->getMorphClass();
        $seen = [];

        foreach (DB::table('assignments')->orderBy('id')->get() as $assignment) {
            $type = $assignment->assignable_type;
            $id = (int) $assignment->assignable_id;

            if ($type === $workItemType) {
                $type = $taskType;
                $id = $this->mappedTaskId($id);
            }

            $key = implode(':', [
                $assignment->enterprise_id,
                $type,
                $id,
                $assignment->user_id ?? 'null',
                $assignment->agent_assignment_id ?? 'null',
            ]);

            if (isset($seen[$key])) {
                throw new RuntimeException("Consolidation would duplicate assignments {$seen[$key]} and {$assignment->id}; reconcile the assignees before retrying.");
            }

            $seen[$key] = $assignment->id;
        }
    }

    private function remapWorkflowAndDecisionReferences(): void
    {
        foreach (['workflows', 'decisions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'work_item_id')) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('work_item_id')->orderBy('id')->get(['id', 'task_id', 'work_item_id']) as $record) {
                if ($record->task_id === null) {
                    DB::table($table)->where('id', $record->id)->update(['task_id' => $this->mappedTaskId((int) $record->work_item_id)]);
                }
            }
        }
    }

    private function remapDependencies(): void
    {
        if (! Schema::hasTable('dependencies')) {
            return;
        }
        $workItemType = ('App'.chr(92).'Models'.chr(92).'WorkItem');
        $taskType = (new Task)->getMorphClass();
        foreach (['predecessor' => 'predecessor_id', 'successor' => 'successor_id'] as $side => $idColumn) {
            $typeColumn = $side.'_type';
            foreach (DB::table('dependencies')->where($typeColumn, $workItemType)->orderBy('id')->get(['id', $idColumn]) as $edge) {
                DB::table('dependencies')->where('id', $edge->id)->update([
                    $typeColumn => $taskType,
                    $idColumn => $this->mappedTaskId((int) $edge->{$idColumn}),
                ]);
            }
        }
    }

    private function remapAssignments(): void
    {
        if (! Schema::hasTable('assignments')) {
            return;
        }
        $workItemType = ('App'.chr(92).'Models'.chr(92).'WorkItem');
        foreach (DB::table('assignments')->where('assignable_type', $workItemType)->orderBy('id')->get(['id', 'assignable_id']) as $assignment) {
            DB::table('assignments')->where('id', $assignment->id)->update([
                'assignable_type' => (new Task)->getMorphClass(),
                'assignable_id' => $this->mappedTaskId((int) $assignment->assignable_id),
            ]);
        }
    }

    private function remapLegacyExecutions(): void
    {
        if (! Schema::hasTable('executions') || ! Schema::hasColumn('executions', 'work_item_id')) {
            return;
        }
        if (Schema::hasColumn('executions', 'task_name') && Schema::hasColumn('executions', 'work_item_name')) {
            DB::table('executions')
                ->whereNull('task_name')
                ->whereNotNull('work_item_name')
                ->update(['task_name' => DB::raw('work_item_name')]);
        }

        foreach (DB::table('executions')->whereNotNull('work_item_id')->orderBy('id')->get(['id', 'task_id', 'work_item_id']) as $execution) {
            $mappedTaskId = $this->mappedTaskId((int) $execution->work_item_id);
            if ($execution->task_id !== null && (int) $execution->task_id !== $mappedTaskId) {
                throw new RuntimeException("Legacy Execution {$execution->id} has a conflicting Task reference and WorkItem reference.");
            }
            if ($execution->task_id === null) {
                DB::table('executions')->where('id', $execution->id)->update(['task_id' => $mappedTaskId]);
            }
        }
    }

    private function mappedTaskId(int $workItemId): int
    {
        $taskId = DB::table('work_item_task_migration_map')->where('work_item_id', $workItemId)->value('task_id');
        if ($taskId === null) {
            throw new RuntimeException("Cannot map WorkItem {$workItemId} to a canonical Task.");
        }

        return (int) $taskId;
    }

    private function dropLegacyColumns(): void
    {
        foreach (['workflows', 'decisions', 'executions'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'work_item_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['work_item_id']);
                $blueprint->dropColumn('work_item_id');
            });
        }

        if (Schema::hasTable('executions') && Schema::hasColumn('executions', 'work_item_name')) {
            Schema::table('executions', function (Blueprint $blueprint): void {
                $blueprint->dropColumn('work_item_name');
            });
        }
    }
};
