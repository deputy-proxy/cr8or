<?php

namespace App\Mcp\Tools;

use App\Models\Audience;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-audience')]
#[Description('Update an audience without changing enterprise ownership.')]
class UpdateAudienceTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['audience_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->min(1)->max(255), 'description' => $schema->string()->max(10000), 'status' => $schema->string()->min(1)->max(100), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['audience_id' => ['required', 'integer', 'min:1', 'exists:audiences,id'], 'name' => ['sometimes', 'string', 'min:1', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'min:1', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return Audience::query()->with('enterprise')->findOrFail((int) $validated['audience_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof Audience) {
            throw new \LogicException('Unexpected audience target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['audience_id' => $target?->getKey()];
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
        if (! $target instanceof Audience) {
            throw new \LogicException('Unexpected audience target.');
        } $attributes = array_intersect_key($validated, array_flip(['name', 'description', 'status']));
        if ($attributes === []) {
            throw new \LogicException('At least one mutable audience field is required.');
        }

        return $domain->updateAudience($actor, $target, $attributes);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Audience) {
            throw new \LogicException('Unexpected audience result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'name' => $record->name, 'description' => $record->description, 'status' => $record->status];
    }
}