<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use InvalidArgumentException;

final class TaskService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Enterprise $enterprise, array $attributes): Task
    {
        $projectId = $this->projectId($enterprise, $attributes['project_id'] ?? null);
        $initiativeId = $this->initiativeId($enterprise, $attributes['initiative_id'] ?? null);
        $this->assertSingleParent($projectId, $initiativeId);

        return $enterprise->tasks()->create([
            'project_id' => $projectId,
            'initiative_id' => $initiativeId,
            'parent_task_id' => $this->parentTaskId($enterprise, $attributes['parent_task_id'] ?? null, $projectId, $initiativeId),
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
        $initiativeId = array_key_exists('initiative_id', $attributes)
            ? $this->initiativeId($enterprise, $attributes['initiative_id'])
            : ($task->initiative_id === null ? null : (int) $task->initiative_id);

        if (array_key_exists('project_id', $attributes) || array_key_exists('initiative_id', $attributes)) {
            $this->assertSingleParent($projectId, $initiativeId);
            $attributes['project_id'] = $projectId;
            $attributes['initiative_id'] = $initiativeId;
        }

        if (array_key_exists('parent_task_id', $attributes) || array_key_exists('project_id', $attributes) || array_key_exists('initiative_id', $attributes)) {
            $parentTaskId = array_key_exists('parent_task_id', $attributes)
                ? $attributes['parent_task_id']
                : $task->parent_task_id;
            $attributes['parent_task_id'] = $this->parentTaskId($enterprise, $parentTaskId, $projectId, $initiativeId);
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
            throw new InvalidArgumentException('The Project must belong to the selected Enterprise.');
        }

        return (int) $project->getKey();
    }

    private function initiativeId(Enterprise $enterprise, mixed $initiativeId): ?int
    {
        if ($initiativeId === null || $initiativeId === '') {
            return null;
        }
        $initiative = Initiative::query()->findOrFail((int) $initiativeId);
        if ((int) $initiative->enterprise_id !== (int) $enterprise->getKey()) {
            throw new InvalidArgumentException('The Initiative must belong to the selected Enterprise.');
        }

        return (int) $initiative->getKey();
    }

    private function assertSingleParent(?int $projectId, ?int $initiativeId): void
    {
        if ($projectId !== null && $initiativeId !== null) {
            throw new InvalidArgumentException('A Task can belong to a Project or Initiative, not both.');
        }
    }

    private function parentTaskId(Enterprise $enterprise, mixed $parentTaskId, ?int $projectId, ?int $initiativeId): ?int
    {
        if ($parentTaskId === null || $parentTaskId === '') {
            return null;
        }
        $parent = Task::query()->findOrFail((int) $parentTaskId);
        if ((int) $parent->enterprise_id !== (int) $enterprise->getKey()
            || $parent->project_id !== $projectId
            || $parent->initiative_id !== $initiativeId) {
            throw new InvalidArgumentException('Parent Task must belong to the same Enterprise and Project or Initiative.');
        }

        return (int) $parent->getKey();
    }
}
