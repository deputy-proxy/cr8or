<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Enums\TaskStatus;
use App\Models\Enterprise;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessibleEnterpriseResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ListTasks implements Operation
{
    public function __construct(private readonly AccessibleEnterpriseResolver $enterprises) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $input): array
    {
        $contextEnterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        Gate::forUser($actor)->authorize('view', $contextEnterprise);

        $scope = (string) ($input['scope'] ?? 'enterprise');
        if (! in_array($scope, ['enterprise', 'all_accessible'], true)) {
            throw ValidationException::withMessages(['scope' => 'Scope must be enterprise or all_accessible.']);
        }

        $targetEnterprises = $scope === 'all_accessible'
            ? $this->enterprises->forActor($actor)
            : collect([$contextEnterprise]);
        $enterpriseIds = array_map('intval', $targetEnterprises->pluck('id')->all());
        $enterpriseNames = $targetEnterprises->mapWithKeys(static fn (Enterprise $enterprise): array => [
            (int) $enterprise->getKey() => $enterprise->name,
        ]);

        $query = Task::query()
            ->whereIn('enterprise_id', $enterpriseIds)
            ->when(isset($input['status']), fn (Builder $query) => $query->where('status', $input['status']),
                fn (Builder $query) => $query->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value]))
            ->when(isset($input['search']), fn (Builder $query) => $query->where('name', 'like', '%'.(string) $input['search'].'%'))
            ->when(isset($input['priority']), fn (Builder $query) => $query->where('priority', $input['priority']))
            ->when(isset($input['project_id']), fn (Builder $query) => $query->where('project_id', $input['project_id']))
            ->when(isset($input['initiative_id']), fn (Builder $query) => $query->where('initiative_id', $input['initiative_id']))
            ->orderBy('enterprise_id')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('id');

        if ($scope === 'all_accessible') {
            $maxItems = max(1, (int) ($input['max_items'] ?? 1000));
            $tasks = $query->limit($maxItems + 1)->get();

            if ($tasks->count() > $maxItems) {
                throw ValidationException::withMessages([
                    'max_items' => "The accessible Enterprise task set exceeds the {$maxItems}-item completeness limit. Increase max_items or narrow the scope; no partial result was returned.",
                ]);
            }

            $tasksByEnterprise = $tasks->groupBy('enterprise_id');
            $reports = $targetEnterprises->map(function (Enterprise $enterprise) use ($tasksByEnterprise, $enterpriseNames): array {
                /** @var \Illuminate\Support\Collection<int, Task> $enterpriseTasks */
                $enterpriseTasks = $tasksByEnterprise->get($enterprise->getKey(), collect());

                return [
                    'enterprise_id' => (int) $enterprise->getKey(),
                    'enterprise_name' => $enterpriseNames->get((int) $enterprise->getKey()),
                    'total_open_tasks' => $enterpriseTasks->count(),
                    'tasks' => $enterpriseTasks->map(fn (Task $task): array => $this->serializeTask($task, $enterprise->name))->values()->all(),
                ];
            })->values()->all();

            return [
                'scope' => 'all_accessible',
                'enterprise_ids' => array_map('intval', $enterpriseIds),
                'enterprises' => $reports,
                'total_open_tasks' => $tasks->count(),
                'max_items' => $maxItems,
                'complete' => true,
            ];
        }

        $tasks = $query->paginate((int) ($input['per_page'] ?? 50), ['*'], 'page', (int) ($input['page'] ?? 1));

        /** @var list<Task> $taskItems */
        $taskItems = $tasks->items();

        return [
            'scope' => 'enterprise',
            'enterprise_id' => (int) $contextEnterprise->getKey(),
            'enterprise_name' => $contextEnterprise->name,
            'items' => array_map(fn (Task $task): array => $this->serializeTask($task, $contextEnterprise->name), $taskItems),
            'pagination' => [
                'page' => $tasks->currentPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'last_page' => $tasks->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTask(Task $task, string $enterpriseName): array
    {
        return [
            'id' => (int) $task->getKey(),
            'enterprise_id' => (int) $task->enterprise_id,
            'enterprise_name' => $enterpriseName,
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
        ];
    }
}
