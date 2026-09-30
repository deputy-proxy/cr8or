<?php

namespace App\Mcp\Tools;

use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\Script;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-script')]
#[Description('Create a script for an authorized ContentItem through the governed marketing script capability.')]
final class CreateScriptTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return [
            'content_item_id' => $schema->integer()->min(1)->required(),
            'title' => $schema->string()->min(1)->max(255)->required(),
            'body' => $schema->string()->max(100000)->required(),
            'asset_requirements' => $schema->array(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    protected static function rules(): array
    {
        return [
            'content_item_id' => ['required', 'integer', 'min:1', 'exists:content_items,id'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'asset_requirements' => ['nullable', 'array'],
            'asset_requirements.*' => ['array'],
            'asset_requirements.*.type' => ['required', 'string', 'min:1', 'max:255'],
            'asset_requirements.*.purpose' => ['required', 'string', 'min:1', 'max:1000'],
            'asset_requirements.*.channel' => ['required', 'string', 'min:1', 'max:255'],
            'asset_requirements.*.platform' => ['required', 'string', 'min:1', 'max:255'],
            'asset_requirements.*.format' => ['required', 'string', 'min:1', 'max:255'],
            'asset_requirements.*.dimensions' => ['nullable', 'array'],
            'asset_requirements.*.dimensions.width' => ['nullable', 'integer', 'min:1'],
            'asset_requirements.*.dimensions.height' => ['nullable', 'integer', 'min:1'],
            'asset_requirements.*.dimensions.aspect_ratio' => ['nullable', 'string', 'max:64'],
            'asset_requirements.*.duration_seconds' => ['nullable', 'numeric', 'min:0'],
            'asset_requirements.*.creative_brief' => ['required', 'string', 'min:1', 'max:10000'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return ContentItem::query()->with('enterprise')->findOrFail((int) $validated['content_item_id'])->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['content_item_id' => $validated['content_item_id']];
    }

    /** @param array<string, mixed> $validated */
    /** @return array{0: string, 1: mixed} */
    // @phpstan-ignore missingType.iterableValue
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        $item = ContentItem::query()->findOrFail((int) $validated['content_item_id']);

        return ['createForContentItem', [Script::class, $item]];
    }

    protected static function target(array $validated): Model
    {
        return ContentItem::query()->findOrFail((int) $validated['content_item_id']);
    }

    protected static function mutate(User $actor, \App\Services\DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        if (! $target instanceof ContentItem) {
            throw new \LogicException('A ContentItem target is required for script creation.');
        }

        return $domain->createScript($actor, $target, $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Script) {
            throw new \LogicException('Unexpected script result.');
        }

        return [
            'id' => $record->getKey(),
            'content_item_id' => $record->content_item_id,
            'agent_assignment_id' => $record->agent_assignment_id,
            'agent_execution_id' => $record->agent_execution_id,
            'title' => $record->title,
            'body' => $record->body,
        ];
    }
}