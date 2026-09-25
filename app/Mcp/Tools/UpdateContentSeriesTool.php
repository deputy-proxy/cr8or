<?php

namespace App\Mcp\Tools;

use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-content-series')]
#[Description('Update a content series while preserving campaign ownership and lifecycle rules.')]
class UpdateContentSeriesTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['content_series_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->min(1)->max(255), 'description' => $schema->string()->max(10000), 'status' => $schema->string()->min(1)->max(100), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['content_series_id' => ['required', 'integer', 'min:1', 'exists:content_series,id'], 'name' => ['sometimes', 'string', 'min:1', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'min:1', 'max:100'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return ContentSeries::query()->with('campaign.enterprise')->findOrFail((int) $validated['content_series_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof ContentSeries) {
            throw new \LogicException('Unexpected content series target.');
        }

        return $target->campaign->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['content_series_id' => $target?->getKey()];
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
        if (! $target instanceof ContentSeries) {
            throw new \LogicException('Unexpected content series target.');
        } $attributes = array_intersect_key($validated, array_flip(['name', 'description', 'status']));
        if ($attributes === []) {
            throw new \LogicException('At least one mutable content series field is required.');
        }

        return $domain->updateContentSeries($actor, $target, $attributes);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof ContentSeries) {
            throw new \LogicException('Unexpected content series result.');
        }

        return ['id' => $record->id, 'campaign_id' => $record->campaign_id, 'name' => $record->name, 'description' => $record->description, 'status' => $record->status];
    }
}