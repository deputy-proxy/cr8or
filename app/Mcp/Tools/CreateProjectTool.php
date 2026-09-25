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

#[Name('create-project')]
#[Description('Create a project under an authorized enterprise with optional strategy/work hierarchy context.')]
class CreateProjectTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'strategy_id' => $schema->integer()->min(1), 'plan_id' => $schema->integer()->min(1), 'initiative_id' => $schema->integer()->min(1), 'name' => $schema->string()->min(1)->max(255)->required(), 'description' => $schema->string()->max(10000), 'status' => $schema->string()->max(100), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'strategy_id' => ['nullable', 'integer', 'min:1', 'exists:strategies,id'], 'plan_id' => ['nullable', 'integer', 'min:1', 'exists:plans,id'], 'initiative_id' => ['nullable', 'integer', 'min:1', 'exists:initiatives,id'], 'name' => ['required', 'string', 'min:1', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'status' => ['nullable', 'string', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['enterprise_id' => $validated['enterprise_id']];
    }

    /** @param array<string, mixed> $validated */
    /** @return array{0: string, 1: mixed} */
    // @phpstan-ignore missingType.iterableValue
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['create', [Project::class, static::enterprise($validated)]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createProject($actor, static::enterprise($validated), $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Project) {
            throw new \LogicException('Unexpected project result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'strategy_id' => $record->strategy_id, 'plan_id' => $record->plan_id, 'initiative_id' => $record->initiative_id, 'name' => $record->name, 'description' => $record->description, 'status' => $record->status];
    }
}