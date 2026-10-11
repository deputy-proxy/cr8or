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

#[Name('update-enterprise-context')]
#[Description('Update the existing business context for an authorized enterprise without changing enterprise ownership.')]
class UpdateEnterpriseContextTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return [
            'enterprise_context_id' => $schema->integer()->min(1)->required(),
            'description' => $schema->string()->max(10000),
            'industry' => $schema->string()->max(255),
            'business_model' => $schema->string()->max(255),
            'target_market' => $schema->string()->max(255),
            'geography' => $schema->string()->max(255),
            'additional_context' => $schema->object(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    protected static function rules(): array
    {
        return [
            'enterprise_context_id' => ['required', 'integer', 'min:1', 'exists:enterprise_contexts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target_market' => ['sometimes', 'nullable', 'string', 'max:255'],
            'geography' => ['sometimes', 'nullable', 'string', 'max:255'],
            'additional_context' => ['sometimes', 'nullable', 'array'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ];
    }

    /** @param array<string, mixed> $validated */
    protected static function target(array $validated): ?Model
    {
        return EnterpriseContext::query()->with('enterprise')->findOrFail((int) $validated['enterprise_context_id']);
    }

    /** @param array<string, mixed> $validated */
    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof EnterpriseContext) {
            throw new \LogicException('Unexpected enterprise context target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['enterprise_context_id' => $target?->getKey()];
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
        if (! $target instanceof EnterpriseContext) {
            throw new \LogicException('Unexpected enterprise context target.');
        }

        $attributes = array_intersect_key($validated, array_flip([
            'description', 'industry', 'business_model', 'target_market', 'geography', 'additional_context',
        ]));

        if ($attributes === []) {
            throw new \LogicException('At least one mutable enterprise context field is required.');
        }

        return $domain->updateEnterpriseContext($actor, $target, $attributes);
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
            'updated_at' => $record->updated_at,
        ];
    }
}
