<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;

class WorkItemService
{
    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Enterprise $enterprise, array $attributes): WorkItem
    {
        return $enterprise->workItems()->create([
            'project_id' => $this->projectId($enterprise, $attributes['project_id'] ?? null),
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, WorkItem $workItem, array $attributes): WorkItem
    {
        if (array_key_exists('project_id', $attributes)) {
            $attributes['project_id'] = $this->projectId($workItem->enterprise, $attributes['project_id']);
        }

        $workItem->update($attributes);

        return $workItem->refresh();
    }

    private function projectId(Enterprise $enterprise, mixed $projectId): ?int
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }

        $project = Project::query()->findOrFail((int) $projectId);

        if ($project->enterprise_id !== $enterprise->getKey()) {
            throw new \InvalidArgumentException('The project must belong to the selected enterprise.');
        }

        return $project->getKey();
    }
}
