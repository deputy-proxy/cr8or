<?php

namespace App\Mcp\Tools;

use App\Models\Campaign;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-content-series')]
#[Description('Create a content series under an authorized campaign.')]
class CreateContentSeriesTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['campaign_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->min(1)->max(255)->required(), 'description' => $schema->string()->max(10000), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['campaign_id' => ['required', 'integer', 'min:1', 'exists:campaigns,id'], 'name' => ['required', 'string', 'min:1', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Campaign::query()->with('enterprise')->findOrFail((int) $validated['campaign_id'])->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['campaign_id' => $validated['campaign_id']];
    }

    /** @param array<string, mixed> $validated */
    /** @return array{0: string, 1: mixed} */
    // @phpstan-ignore missingType.iterableValue
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        $campaign = Campaign::query()->findOrFail((int) $validated['campaign_id']);

        return ['create', [ContentSeries::class, $campaign]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        $campaign = Campaign::query()->findOrFail((int) $validated['campaign_id']);

        return $domain->createContentSeries($actor, $campaign, $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof ContentSeries) {
            throw new \LogicException('Unexpected content series result.');
        }

        return ['id' => $record->id, 'campaign_id' => $record->campaign_id, 'name' => $record->name, 'description' => $record->description, 'status' => $record->status];
    }
}