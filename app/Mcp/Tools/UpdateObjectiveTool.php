<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\Objective;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-objective')]
#[Description('Update an objective without changing enterprise ownership.')]
class UpdateObjectiveTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['objective_id' => $schema->integer()->min(1)->required(), 'goal_id' => $schema->integer()->min(1), 'kpi_id' => $schema->integer()->min(1), 'name' => $schema->string()->min(1)->max(255), 'description' => $schema->string()->max(10000), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['objective_id' => ['required', 'integer', 'min:1', 'exists:objectives,id'], 'goal_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:goals,id'], 'kpi_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:kpis,id'], 'name' => ['sometimes', 'string', 'min:1', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return Objective::query()->with('enterprise')->findOrFail((int) $validated['objective_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof Objective) {
            throw new \LogicException('Unexpected objective target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['objective_id' => $target?->getKey()];
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
        if (! $target instanceof Objective) {
            throw new \LogicException('Unexpected objective target.');
        } $attributes = array_intersect_key($validated, array_flip(['goal_id', 'kpi_id', 'name', 'description']));
        if ($attributes === []) {
            throw new \LogicException('At least one mutable objective field is required.');
        }

        return $domain->updateObjective($actor, $target, $attributes);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Objective) {
            throw new \LogicException('Unexpected objective result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'goal_id' => $record->goal_id, 'kpi_id' => $record->kpi_id, 'name' => $record->name, 'description' => $record->description];
    }
}