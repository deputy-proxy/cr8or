<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class WorkContextAssembler
{
    private const CONTEXT_LIMIT = 100;

    private const RELATION_LIMIT = 20;

    /** @return array<string, mixed> */
    public function assemble(User $user, Enterprise $enterprise): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $projects = $enterprise->projects()
            ->select([
                'id',
                'enterprise_id',
                'name',
                'description',
                'status',
                'strategy_id',
                'plan_id',
                'initiative_id',
            ])
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $tasks = $enterprise->tasks()
            ->select([
                'id',
                'enterprise_id',
                'project_id',
                'parent_task_id',
                'name',
                'description',
                'status',
                'priority',
                'due_at',
            ])
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $workItems = $enterprise->workItems()
            ->select([
                'id',
                'enterprise_id',
                'project_id',
                'name',
                'description',
                'status',
            ])
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $milestones = $enterprise->milestones()
            ->select([
                'id',
                'enterprise_id',
                'project_id',
                'name',
                'description',
                'due_at',
                'status',
            ])
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $taskRelations = $this->taskRelations($enterprise, $tasks);
        $workReferences = $this->workReferences($projects, $tasks, $workItems, $milestones);

        return [
            'enterprise' => $this->enterpriseIdentity($enterprise),
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'strategy_id' => $project->strategy_id,
                'plan_id' => $project->plan_id,
                'initiative_id' => $project->initiative_id,
            ])->all(),
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->getKey(),
                'project_id' => $task->project_id,
                'parent_task_id' => $task->parent_task_id,
                'name' => $task->name,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $this->timestamp($task->due_at),
                'children' => $taskRelations['children'][$task->getKey()] ?? [],
                'parent' => $taskRelations['parents'][$task->getKey()] ?? null,
            ])->all(),
            'work_items' => $workItems->map(fn (WorkItem $item) => [
                'id' => $item->getKey(),
                'project_id' => $item->project_id,
                'name' => $item->name,
                'description' => $item->description,
                'status' => $item->status,
            ])->all(),
            'milestones' => $milestones->map(fn (Milestone $milestone) => [
                'id' => $milestone->getKey(),
                'project_id' => $milestone->project_id,
                'name' => $milestone->name,
                'description' => $milestone->description,
                'due_at' => $this->timestamp($milestone->due_at),
                'status' => $milestone->status,
            ])->all(),
            'assignments' => $this->assignments($enterprise, $workReferences),
            'dependencies' => $this->dependencies($enterprise, $workReferences),
            'execution_state' => $this->executionState($enterprise),
        ];
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array{parents: array<int, array<string, mixed>>, children: array<int, list<int>>}
     */
    private function taskRelations(Enterprise $enterprise, Collection $tasks): array
    {
        if ($tasks->isEmpty()) {
            return ['parents' => [], 'children' => []];
        }

        $taskIds = $tasks->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $parentIds = $tasks->pluck('parent_task_id')->filter()->unique()->values()->all();

        $parents = $parentIds === []
            ? collect()
            : Task::query()
                ->where('enterprise_id', $enterprise->getKey())
                ->whereIn('id', $parentIds)
                ->select(['id', 'enterprise_id', 'project_id', 'name', 'status'])
                ->get()
                ->keyBy('id');

        $children = Task::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->whereIn('parent_task_id', $taskIds)
            ->select(['id', 'parent_task_id'])
            ->orderBy('id')
            ->get()
            ->groupBy('parent_task_id')
            ->mapWithKeys(function (Collection $items, $parentId): array {
                return [(int) $parentId => $items
                    ->take(self::RELATION_LIMIT)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all()];
            });

        /** @var Collection<int, Task> $parents */
        $parentData = $tasks->mapWithKeys(function (Task $task) use ($parents): array {
            if ($task->parent_task_id === null) {
                return [];
            }

            $parent = $parents->get($task->parent_task_id);

            return $parent === null ? [] : [
                (int) $task->getKey() => [
                    'id' => $parent->getKey(),
                    'project_id' => $parent->project_id,
                    'name' => $parent->name,
                    'status' => $parent->status,
                ],
            ];
        });

        /** @var array<int, list<int>> $childrenData */
        $childrenData = $children->all();

        return [
            'parents' => $parentData->all(),
            'children' => $childrenData,
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, WorkItem>  $workItems
     * @param  Collection<int, Milestone>  $milestones
     * @return array<string, array<int, int>>
     */
    private function workReferences(
        Collection $projects,
        Collection $tasks,
        Collection $workItems,
        Collection $milestones,
    ): array {
        return [
            Project::class => $projects->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            Task::class => $tasks->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            WorkItem::class => $workItems->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            Milestone::class => $milestones->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  array<string, array<int, int>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function assignments(Enterprise $enterprise, array $references): array
    {
        $hasReferences = false;

        foreach ($references as $ids) {
            if ($ids !== []) {
                $hasReferences = true;
                break;
            }
        }

        if (! $hasReferences) {
            return [];
        }

        return Assignment::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->where(function ($query) use ($references): void {
                foreach ($references as $type => $ids) {
                    if ($ids === []) {
                        continue;
                    }

                    $query->orWhere(function ($query) use ($type, $ids): void {
                        $query->where('assignable_type', $this->morphClass($type))
                            ->whereIn('assignable_id', $ids);
                    });
                }
            })
            ->select([
                'id',
                'assignable_type',
                'assignable_id',
                'user_id',
                'agent_assignment_id',
            ])
            ->orderByDesc('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get()
            ->map(fn (Assignment $assignment) => [
                'id' => $assignment->getKey(),
                'assignable_type' => $assignment->assignable_type,
                'assignable_id' => $assignment->assignable_id,
                'user_id' => $assignment->user_id,
                'agent_assignment_id' => $assignment->agent_assignment_id,
            ])->all();
    }

    /**
     * @param  array<string, array<int, int>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function dependencies(Enterprise $enterprise, array $references): array
    {
        $hasReferences = false;

        foreach ($references as $ids) {
            if ($ids !== []) {
                $hasReferences = true;
                break;
            }
        }

        if (! $hasReferences) {
            return [];
        }

        $dependencies = Dependency::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->where(function ($query) use ($references): void {
                foreach ($references as $type => $ids) {
                    if ($ids === []) {
                        continue;
                    }

                    $query->orWhere(function ($query) use ($type, $ids): void {
                        $query
                            ->where(function ($query) use ($type, $ids): void {
                                $query->where('predecessor_type', $this->morphClass($type))
                                    ->whereIn('predecessor_id', $ids);
                            })
                            ->orWhere(function ($query) use ($type, $ids): void {
                                $query->where('successor_type', $this->morphClass($type))
                                    ->whereIn('successor_id', $ids);
                            });
                    });
                }
            })
            ->select([
                'id',
                'enterprise_id',
                'project_id',
                'predecessor_type',
                'predecessor_id',
                'successor_type',
                'successor_id',
                'type',
            ])
            ->orderByDesc('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $endpoints = $this->dependencyEndpoints($enterprise, $dependencies);

        return $dependencies->filter(function (Dependency $dependency) use ($endpoints): bool {
            $predecessor = $endpoints[$dependency->predecessor_type][$dependency->predecessor_id] ?? null;
            $successor = $endpoints[$dependency->successor_type][$dependency->successor_id] ?? null;

            return $predecessor !== null && $successor !== null;
        })->map(fn (Dependency $dependency) => [
            'id' => $dependency->getKey(),
            'project_id' => $dependency->project_id,
            'predecessor' => [
                'type' => $dependency->predecessor_type,
                'id' => $dependency->predecessor_id,
            ],
            'successor' => [
                'type' => $dependency->successor_type,
                'id' => $dependency->successor_id,
            ],
            'type' => $dependency->type,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Dependency>  $dependencies
     * @return array<string, array<int, true>>
     */
    private function dependencyEndpoints(Enterprise $enterprise, Collection $dependencies): array
    {
        $references = [];

        foreach ($dependencies as $dependency) {
            foreach ([
                [$dependency->predecessor_type, $dependency->predecessor_id],
                [$dependency->successor_type, $dependency->successor_id],
            ] as [$type, $id]) {
                if (! DependencyService::isSupportedEndpointType($type)) {
                    continue;
                }

                $references[$type][] = (int) $id;
            }
        }

        $endpoints = [];

        foreach ($references as $type => $ids) {
            $model = DependencyService::endpointClass($type);
            $records = $model::query()
                ->where('enterprise_id', $enterprise->getKey())
                ->whereIn('id', array_values(array_unique($ids)))
                ->select(['id', 'enterprise_id'])
                ->get();

            foreach ($records as $record) {
                $endpoints[$type][$record->getKey()] = true;
            }
        }

        return $endpoints;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function executionState(Enterprise $enterprise): array
    {
        $workflows = Workflow::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->select([
                'id',
                'enterprise_id',
                'name',
                'status',
                'project_id',
                'task_id',
                'work_item_id',
            ])
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        if ($workflows->isEmpty()) {
            return [];
        }

        $workflowIds = $workflows->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $latestExecutionIds = WorkflowExecution::query()
            ->selectRaw('workflow_id, MAX(id) as id')
            ->whereIn('workflow_id', $workflowIds)
            ->groupBy('workflow_id');

        $executions = WorkflowExecution::query()
            ->joinSub($latestExecutionIds, 'latest_executions', function ($join): void {
                $join->on('workflow_executions.workflow_id', '=', 'latest_executions.workflow_id')
                    ->on('workflow_executions.id', '=', 'latest_executions.id');
            })
            ->select([
                'workflow_executions.id',
                'workflow_executions.workflow_id',
                'workflow_executions.workflow_version_id',
                'workflow_executions.workflow_version',
                'workflow_executions.status',
                'workflow_executions.current_stage_key',
                'workflow_executions.started_at',
                'workflow_executions.completed_at',
                'workflow_executions.failure_reason',
                'workflow_executions.correlation_id',
                'workflow_executions.idempotency_key',
            ])
            ->get()
            ->keyBy('workflow_id');

        /** @var Collection<int, WorkflowExecution> $executions */
        $executions = $executions;

        return $workflows->map(function (Workflow $workflow) use ($executions): array {
            $execution = $executions->get($workflow->getKey());

            return [
                'workflow' => [
                    'id' => $workflow->getKey(),
                    'name' => $workflow->name,
                    'status' => $workflow->status,
                    'project_id' => $workflow->project_id,
                    'task_id' => $workflow->task_id,
                    'work_item_id' => $workflow->work_item_id,
                ],
                'execution' => $execution === null ? null : [
                    'id' => $execution->getKey(),
                    'workflow_version_id' => $execution->workflow_version_id,
                    'workflow_version' => $execution->workflow_version,
                    'status' => $execution->status,
                    'current_stage_key' => $execution->current_stage_key,
                    'started_at' => $this->isoTimestamp($execution->started_at),
                    'completed_at' => $this->isoTimestamp($execution->completed_at),
                    'failure_reason' => $execution->failure_reason,
                    'correlation_id' => $execution->correlation_id,
                    'idempotency_key' => $execution->idempotency_key,
                ],
            ];
        })->all();
    }

    private function morphClass(string $model): string
    {
        return match ($model) {
            Project::class => (new Project)->getMorphClass(),
            Task::class => (new Task)->getMorphClass(),
            WorkItem::class => (new WorkItem)->getMorphClass(),
            Milestone::class => (new Milestone)->getMorphClass(),
            default => throw new \InvalidArgumentException("Unsupported work context model [{$model}]."),
        };
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function isoTimestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toISOString() : ($value === null ? null : (string) $value);
    }

    /** @return array{id: int|string, name: string, slug: string, status: string} */
    private function enterpriseIdentity(Enterprise $enterprise): array
    {
        return [
            'id' => $enterprise->getKey(),
            'name' => $enterprise->name,
            'slug' => $enterprise->slug,
            'status' => $enterprise->status,
        ];
    }
}