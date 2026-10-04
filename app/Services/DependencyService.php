<?php

namespace App\Services;

use App\Enums\DependencyType;
use App\Models\Dependency;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class DependencyService
{
    /** @var array<int, class-string<Project|Task|WorkItem|Milestone>> */
    public const ENDPOINT_TYPES = [
        Project::class,
        Task::class,
        WorkItem::class,
        Milestone::class,
    ];

    public static function validate(Dependency $dependency): void
    {
        $predecessor = self::resolveEndpoint($dependency->predecessor_type, $dependency->predecessor_id, 'predecessor');
        $successor = self::resolveEndpoint($dependency->successor_type, $dependency->successor_id, 'successor');
        $enterpriseId = $dependency->getAttribute('enterprise_id');

        if ($enterpriseId === null || (int) $predecessor->getAttribute('enterprise_id') !== (int) $enterpriseId || (int) $successor->getAttribute('enterprise_id') !== (int) $enterpriseId) {
            throw new InvalidArgumentException('Dependency endpoints must belong to the dependency Enterprise.');
        }

        $projectId = $dependency->getAttribute('project_id');

        if ($projectId !== null) {
            $project = Project::query()->whereKey($projectId)->first();

            if ($project === null || (int) $project->enterprise_id !== (int) $enterpriseId) {
                throw new InvalidArgumentException('The dependency project must belong to the dependency Enterprise.');
            }

            foreach ([$predecessor, $successor] as $endpoint) {
                if ($endpoint instanceof Project || ! array_key_exists('project_id', $endpoint->getAttributes())) {
                    throw new InvalidArgumentException('Project-scoped dependencies require project-scoped endpoints.');
                }

                if ((int) $endpoint->getAttribute('project_id') !== (int) $projectId) {
                    throw new InvalidArgumentException('Project-scoped dependencies require both endpoints to belong to the selected Project.');
                }
            }
        }

        if ($predecessor->getMorphClass() === $successor->getMorphClass() && (int) $predecessor->getKey() === (int) $successor->getKey()) {
            throw new InvalidArgumentException('A dependency cannot point to the same record.');
        }

        $duplicate = Dependency::query()
            ->when($dependency->exists, fn ($query) => $query->where('id', '!=', $dependency->getKey()))
            ->where('enterprise_id', $enterpriseId)
            ->where('predecessor_type', $predecessor->getMorphClass())
            ->where('predecessor_id', $predecessor->getKey())
            ->where('successor_type', $successor->getMorphClass())
            ->where('successor_id', $successor->getKey())
            ->where('type', DependencyType::Blocks->value)
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException('The dependency already exists.');
        }

        if (self::createsCycle($dependency, $predecessor, $successor)) {
            throw new InvalidArgumentException('The dependency would create a cycle in the Work dependency graph.');
        }
    }

    private static function resolveEndpoint(?string $type, mixed $id, string $role): Project|Task|WorkItem|Milestone
    {
        $class = self::endpointClass($type);

        if ($id === null || $id === '') {
            throw new InvalidArgumentException("The {$role} endpoint is required.");
        }

        $endpoint = $class::query()->whereKey($id)->first();

        if ($endpoint === null) {
            throw new InvalidArgumentException("The {$role} endpoint does not exist.");
        }

        return $endpoint;
    }

    /** @return class-string<Project|Task|WorkItem|Milestone> */
    public static function endpointClass(?string $type): string
    {
        foreach (self::ENDPOINT_TYPES as $class) {
            $instance = new $class;

            if ($type === $class || $type === $instance->getMorphClass()) {
                return $class;
            }
        }

        throw new InvalidArgumentException('Unsupported Work dependency endpoint type.');
    }

    public static function isSupportedEndpointType(?string $type): bool
    {
        try {
            self::endpointClass($type);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function createsCycle(Dependency $candidate, Model $predecessor, Model $successor): bool
    {
        $edges = Dependency::query()
            ->where('enterprise_id', $candidate->getAttribute('enterprise_id'))
            ->where('type', DependencyType::Blocks->value)
            ->when($candidate->exists, fn ($query) => $query->where('id', '!=', $candidate->getKey()))
            ->get(['predecessor_type', 'predecessor_id', 'successor_type', 'successor_id'])
            ->map(fn (Dependency $edge): array => [
                'predecessor_type' => $edge->predecessor_type,
                'predecessor_id' => (int) $edge->predecessor_id,
                'successor_type' => $edge->successor_type,
                'successor_id' => (int) $edge->successor_id,
            ])
            ->all();

        $edges[] = [
            'predecessor_type' => $predecessor->getMorphClass(),
            'predecessor_id' => (int) $predecessor->getKey(),
            'successor_type' => $successor->getMorphClass(),
            'successor_id' => (int) $successor->getKey(),
        ];

        $queue = [[$successor->getMorphClass(), (int) $successor->getKey()]];
        $visited = [];
        $targetType = $predecessor->getMorphClass();
        $targetId = (int) $predecessor->getKey();

        while ($queue !== []) {
            [$type, $id] = array_shift($queue);
            $key = "{$type}:{$id}";

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;

            if ($type === $targetType && $id === $targetId) {
                return true;
            }

            foreach ($edges as $edge) {
                if ($edge['predecessor_type'] === $type && $edge['predecessor_id'] === $id) {
                    $queue[] = [$edge['successor_type'], $edge['successor_id']];
                }
            }
        }

        return false;
    }
}
