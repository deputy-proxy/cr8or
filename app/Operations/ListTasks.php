<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Enums\TaskStatus;
use App\Models\Enterprise;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class ListTasks implements Operation
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function execute(User $actor, array $input): array
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        Gate::forUser($actor)->authorize('view', $enterprise);

        $tasks = $enterprise->tasks()
            ->when(isset($input['status']), fn (Builder $query) => $query->where('status', $input['status']),
                fn (Builder $query) => $query->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value]))
            ->when(isset($input['search']), fn (Builder $query) => $query->where('name', 'like', '%'.(string) $input['search'].'%'))
            ->when(isset($input['priority']), fn (Builder $query) => $query->where('priority', $input['priority']))
            ->when(isset($input['project_id']), fn (Builder $query) => $query->where('project_id', $input['project_id']))
            ->when(isset($input['initiative_id']), fn (Builder $query) => $query->where('initiative_id', $input['initiative_id']))
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? 50), ['*'], 'page', (int) ($input['page'] ?? 1));

        /** @var list<Task> $taskItems */
        $taskItems = $tasks->items();

        return [
            'items' => array_map(static fn (Task $task): array => [
                'id' => (int) $task->getKey(),
                'enterprise_id' => (int) $task->enterprise_id,
                'name' => $task->name,
                'description' => $task->description,
                'status' => $task->status->value,
                'parent_task_id' => $task->parent_task_id === null ? null : (int) $task->parent_task_id,
                'priority' => $task->priority,
                'due_at' => $task->due_at === null ? null : Carbon::parse($task->due_at)->toIso8601String(),
                'project_id' => $task->project_id === null ? null : (int) $task->project_id,
                'initiative_id' => $task->initiative_id === null ? null : (int) $task->initiative_id,
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
            ], $taskItems),
            'pagination' => [
                'page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ];
    }
}
