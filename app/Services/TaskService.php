<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

final class TaskService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Enterprise $enterprise, array $attributes): Task
    {
        return $enterprise->tasks()->create([
            'project_id' => $this->projectId($enterprise, $attributes['project_id'] ?? null),
            'parent_task_id' => $this->parentTaskId($enterprise, $attributes['parent_task_id'] ?? null, $attributes['project_id'] ?? null),
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'todo',
            'priority' => $attributes['priority'] ?? 'normal',
            'due_at' => $attributes['due_at'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Task $task, array $attributes): Task
    {
        $enterprise = $task->enterprise;
        $projectId = array_key_exists('project_id', $attributes)
            ? $this->projectId($enterprise, $attributes['project_id'])
            : ($task->project_id === null ? null : (int) $task->project_id);

        if (array_key_exists('project_id', $attributes)) {
            $attributes['project_id'] = $projectId;
        }

        if (array_key_exists('parent_task_id', $attributes) || array_key_exists('project_id', $attributes)) {
            $parentTaskId = array_key_exists('parent_task_id', $attributes)
                ? $attributes['parent_task_id']
                : $task->parent_task_id;
            $attributes['parent_task_id'] = $this->parentTaskId($enterprise, $parentTaskId, $projectId);
        }

        $task->update($attributes);

        return $task->refresh();
    }

    private function projectId(Enterprise $enterprise, mixed $projectId): ?int
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }
        $project = Project::query()->findOrFail((int) $projectId);
        if ((int) $project->enterprise_id !== (int) $enterprise->getKey()) {
            throw new \InvalidArgumentException('The Project must belong to the selected Enterprise.');
        }

        return (int) $project->getKey();
    }

    private function parentTaskId(Enterprise $enterprise, mixed $parentTaskId, mixed $projectId): ?int
    {
        if ($parentTaskId === null || $parentTaskId === '') {
            return null;
        }
        $parent = Task::query()->findOrFail((int) $parentTaskId);
        if ((int) $parent->enterprise_id !== (int) $enterprise->getKey()
            || (($parent->project_id === null) !== ($projectId === null || $projectId === '')) || ($parent->project_id !== null && (int) $parent->project_id !== (int) $projectId)) {
            throw new \InvalidArgumentException('Parent Task must belong to the same Enterprise and Project.');
        }

        return (int) $parent->getKey();
    }
}
