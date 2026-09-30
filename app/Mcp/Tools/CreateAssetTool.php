<?php

namespace App\Mcp\Tools;

use App\Models\Asset;
use App\Models\Enterprise;
use App\Models\Script;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-asset')]
#[Description('Plan an asset required by an authorized script. Planning creates a pending record only and does not generate media.')]
final class CreateAssetTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return [
            'script_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255)->required(),
            'type' => $schema->string()->min(1)->max(255)->required(),
            'purpose' => $schema->string()->min(1)->max(1000)->required(),
            'channel' => $schema->string()->min(1)->max(255)->required(),
            'platform' => $schema->string()->min(1)->max(255)->required(),
            'format' => $schema->string()->min(1)->max(255)->required(),
            'width' => $schema->integer()->min(1),
            'height' => $schema->integer()->min(1),
            'aspect_ratio' => $schema->string()->max(64),
            'duration_seconds' => $schema->number()->min(0),
            'creative_brief' => $schema->string()->min(1)->max(10000)->required(),
            'agent_assignment_id' => $schema->integer()->min(1)->required(),
            'agent_execution_id' => $schema->integer()->min(1)->required(),
        ];
    }

    protected static function rules(): array
    {
        return [
            'script_id' => ['required', 'integer', 'min:1', 'exists:scripts,id'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'type' => ['required', 'string', 'min:1', 'max:255'],
            'purpose' => ['required', 'string', 'min:1', 'max:1000'],
            'channel' => ['required', 'string', 'min:1', 'max:255'],
            'platform' => ['required', 'string', 'min:1', 'max:255'],
            'format' => ['required', 'string', 'min:1', 'max:255'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'aspect_ratio' => ['nullable', 'string', 'max:64'],
            'duration_seconds' => ['nullable', 'numeric', 'min:0'],
            'creative_brief' => ['required', 'string', 'min:1', 'max:10000'],
            'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['required', 'integer', 'min:1', 'exists:agent_executions,id'],
        ];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Script::query()->with('contentItem.enterprise')->findOrFail((int) $validated['script_id'])->contentItem->enterprise;
    }

    /** @param array<string, mixed> $validated */
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['script_id' => $validated['script_id']];
    }

    /** @param array<string, mixed> $validated */
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        $script = Script::query()->findOrFail((int) $validated['script_id']);

        return ['createForScript', [Asset::class, $script]];
    }

    protected static function target(array $validated): Model
    {
        return Script::query()->findOrFail((int) $validated['script_id']);
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        if (! $target instanceof Script) {
            throw new \LogicException('A Script target is required for planned asset creation.');
        }

        return $domain->createPlannedAsset($actor, $target, $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Asset) {
            throw new \LogicException('Unexpected asset result.');
        }

        return [
            'id' => $record->getKey(),
            'script_id' => $record->script_id,
            'content_item_id' => $record->content_item_id,
            'status' => $record->status,
            'type' => $record->type,
            'purpose' => $record->purpose,
            'channel' => $record->channel,
            'platform' => $record->platform,
            'format' => $record->format,
            'dimensions' => $record->dimensions,
            'duration_seconds' => $record->duration_seconds,
            'creative_brief' => $record->creative_brief,
        ];
    }
}