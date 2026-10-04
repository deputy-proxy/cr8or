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
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $tasks = $enterprise->tasks()
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $workItems = $enterprise->workItems()
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $milestones = $enterprise->milestones()
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

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
                'children' => $task->children()
                    ->orderBy('id')
                    ->limit(self::RELATION_LIMIT)
                    ->pluck('id')
                    ->all(),
                'parent' => $task->parent === null ? null : [
                    'id' => $task->parent->getKey(),
                    'project_id' => $task->parent->project_id,
                    'name' => $task->parent->name,
                    'status' => $task->parent->status,
                ],
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
        $query = Assignment::query()
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
            ->orderByDesc('id')
            ->limit(self::CONTEXT_LIMIT);

        return $query->get()->map(fn (Assignment $assignment) => [
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
        $query = Dependency::query()
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
            ->orderByDesc('id')
            ->limit(self::CONTEXT_LIMIT);

        return $query->get()->filter(function (Dependency $dependency) use ($enterprise): bool {
            if (! DependencyService::isSupportedEndpointType($dependency->predecessor_type) || ! DependencyService::isSupportedEndpointType($dependency->successor_type)) {
                return false;
            }

            try {
                $predecessor = DependencyService::endpointClass($dependency->predecessor_type)::query()->whereKey($dependency->predecessor_id)->first();
                $successor = DependencyService::endpointClass($dependency->successor_type)::query()->whereKey($dependency->successor_id)->first();
            } catch (\InvalidArgumentException) {
                return false;
            }

            return $predecessor !== null
                && $successor !== null
                && (int) $predecessor->enterprise_id === (int) $enterprise->getKey()
                && (int) $successor->enterprise_id === (int) $enterprise->getKey();
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
     * @return array<int, array<string, mixed>>
     */
    private function executionState(Enterprise $enterprise): array
    {
        $workflows = Workflow::query()
            ->where('enterprise_id', $enterprise->getKey())
            ->with(['project:id,name', 'task:id,name', 'workItem:id,name'])
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::CONTEXT_LIMIT)
            ->get();

        $executions = WorkflowExecution::query()
            ->whereIn('workflow_id', $workflows->pluck('id')->all())
            ->orderByDesc('id')
            ->get()
            ->groupBy('workflow_id')
            ->map(fn (Collection $items) => $items->first());

        return $workflows->map(function (Workflow $workflow) use ($executions) {
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
                    'started_at' => $execution->started_at?->toISOString(),
                    'completed_at' => $execution->completed_at?->toISOString(),
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