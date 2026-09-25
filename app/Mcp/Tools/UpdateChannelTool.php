<?php

namespace App\Mcp\Tools;

use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-channel')]
#[Description('Update a channel without changing enterprise ownership.')]
class UpdateChannelTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['channel_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->min(1)->max(255), 'type' => $schema->string()->min(1)->max(100), 'status' => $schema->string()->min(1)->max(100), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['channel_id' => ['required', 'integer', 'min:1', 'exists:channels,id'], 'name' => ['sometimes', 'string', 'min:1', 'max:255'], 'type' => ['sometimes', 'string', 'min:1', 'max:100'], 'status' => ['sometimes', 'string', 'min:1', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return Channel::query()->with('enterprise')->findOrFail((int) $validated['channel_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof Channel) {
            throw new \LogicException('Unexpected channel target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['channel_id' => $target?->getKey()];
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
        if (! $target instanceof Channel) {
            throw new \LogicException('Unexpected channel target.');
        } $attributes = array_intersect_key($validated, array_flip(['name', 'type', 'status']));
        if ($attributes === []) {
            throw new \LogicException('At least one mutable channel field is required.');
        }

        return $domain->updateChannel($actor, $target, $attributes);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Channel) {
            throw new \LogicException('Unexpected channel result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'name' => $record->name, 'type' => $record->type, 'status' => $record->status];
    }
}