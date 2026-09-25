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

#[Name('create-channel')]
#[Description('Create a channel under an authorized enterprise.')]
class CreateChannelTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->min(1)->max(255)->required(), 'type' => $schema->string()->min(1)->max(100)->required(), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'name' => ['required', 'string', 'min:1', 'max:255'], 'type' => ['required', 'string', 'min:1', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
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
        return ['create', [Channel::class, static::enterprise($validated)]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createChannel($actor, static::enterprise($validated), $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Channel) {
            throw new \LogicException('Unexpected channel result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'name' => $record->name, 'type' => $record->type, 'status' => $record->status];
    }
}
