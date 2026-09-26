<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-enterprise-context')]
#[Description('Create business context for an authorized enterprise. Each enterprise can have one context record.')]
class CreateEnterpriseContextTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'description' => $schema->string()->max(10000),
            'industry' => $schema->string()->max(255),
            'business_model' => $schema->string()->max(255),
            'target_market' => $schema->string()->max(255),
            'geography' => $schema->string()->max(255),
            'additional_context' => $schema->object(),
        ];
    }

    protected static function rules(): array
    {
        return [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'description' => ['nullable', 'string', 'max:10000'],
            'industry' => ['nullable', 'string', 'max:255'],
            'business_model' => ['nullable', 'string', 'max:255'],
            'target_market' => ['nullable', 'string', 'max:255'],
            'geography' => ['nullable', 'string', 'max:255'],
            'additional_context' => ['nullable', 'array'],
        ];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['enterprise_id' => $validated['enterprise_id']];
    }

    /** @param array<string, mixed> $validated */
    /** @return array{0: string, 1: mixed} */
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['createForEnterprise', [EnterpriseContext::class, static::enterprise($validated)]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createEnterpriseContext($actor, static::enterprise($validated), $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof EnterpriseContext) {
            throw new \LogicException('Unexpected enterprise context result.');
        }

        return [
            'id' => $record->id,
            'enterprise_id' => $record->enterprise_id,
            'description' => $record->description,
            'industry' => $record->industry,
            'business_model' => $record->business_model,
            'target_market' => $record->target_market,
            'geography' => $record->geography,
            'additional_context' => $record->additional_context,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }
}
