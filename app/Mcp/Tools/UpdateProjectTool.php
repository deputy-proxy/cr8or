<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\Project;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-project')]
#[Description('Update a project without changing enterprise ownership.')]
class UpdateProjectTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['project_id' => $schema->integer()->min(1)->required(), 'strategy_id' => $schema->integer()->min(1), 'plan_id' => $schema->integer()->min(1), 'initiative_id' => $schema->integer()->min(1), 'name' => $schema->string()->min(1)->max(255), 'description' => $schema->string()->max(10000), 'status' => $schema->string()->max(100), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['project_id' => ['required', 'integer', 'min:1', 'exists:projects,id'], 'strategy_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:strategies,id'], 'plan_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:plans,id'], 'initiative_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:initiatives,id'], 'name' => ['sometimes', 'string', 'min:1', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return Project::query()->with('enterprise')->findOrFail((int) $validated['project_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof Project) {
            throw new \LogicException('Unexpected project target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['project_id' => $target?->getKey()];
    }

    /** @param array<string, mixed> $validated */
    /** @return array{0: string, 1: mixed} */
    // @phpstan-ignore missingType.iterableValue
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['update', $target];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        if (! $target instanceof Project) {
            throw new \LogicException('Unexpected project target.');
        } $attributes = array_intersect_key($validated, array_flip(['strategy_id', 'plan_id', 'initiative_id', 'name', 'description', 'status']));
        if ($attributes === []) {
            throw new \LogicException('At least one mutable project field is required.');
        }

        return $domain->updateProject($actor, $target, $attributes);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Project) {
            throw new \LogicException('Unexpected project result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'strategy_id' => $record->strategy_id, 'plan_id' => $record->plan_id, 'initiative_id' => $record->initiative_id, 'name' => $record->name, 'description' => $record->description, 'status' => $record->status];
    }
}