<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

final class UpdateTask implements Operation
{
    public function __construct(private readonly TaskService $tasks) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): Task
    {
        $task = ($input['task'] ?? null) instanceof Task
            ? $input['task']
            : Task::query()->findOrFail((int) $input['task_id']);

        return $this->tasks->update(
            $actor,
            $task,
            $input['attributes'] ?? array_intersect_key($input, array_flip(['name', 'description', 'status', 'project_id', 'parent_task_id', 'priority', 'due_at'])),
        );
    }
}
