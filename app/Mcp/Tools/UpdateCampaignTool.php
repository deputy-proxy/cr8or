<?php

namespace App\Mcp\Tools;

use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-campaign')]
#[Description('Update a campaign while preserving enterprise ownership and lifecycle rules.')]
class UpdateCampaignTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['campaign_id' => $schema->integer()->min(1)->required(), 'marketing_strategy_id' => $schema->integer()->min(1), 'name' => $schema->string()->min(1)->max(255), 'description' => $schema->string()->max(10000), 'status' => $schema->string()->min(1)->max(100), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['campaign_id' => ['required', 'integer', 'min:1', 'exists:campaigns,id'], 'marketing_strategy_id' => ['sometimes', 'integer', 'min:1', 'exists:marketing_strategies,id'], 'name' => ['sometimes', 'string', 'min:1', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'min:1', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return Campaign::query()->with('enterprise')->findOrFail((int) $validated['campaign_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof Campaign) {
            throw new \LogicException('Unexpected campaign target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['campaign_id' => $target?->getKey()];
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
        if (! $target instanceof Campaign) {
            throw new \LogicException('Unexpected campaign target.');
        } $attributes = array_intersect_key($validated, array_flip(['marketing_strategy_id', 'name', 'description', 'status']));
        if ($attributes === []) {
            throw new \LogicException('At least one mutable campaign field is required.');
        }

        return $domain->updateCampaign($actor, $target, $attributes);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Campaign) {
            throw new \LogicException('Unexpected campaign result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'marketing_strategy_id' => $record->marketing_strategy_id, 'name' => $record->name, 'description' => $record->description, 'status' => $record->status];
    }
}