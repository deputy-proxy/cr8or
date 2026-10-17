<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DefinesAudiencePersonaSchema;
use App\Models\Audience;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-audience')]
#[Description('Update a client persona without changing enterprise ownership. Omitted fields and omitted product_ids are preserved; product_ids: [] clears product associations.')]
class UpdateAudienceTool extends DomainMutationTool
{
    use DefinesAudiencePersonaSchema;

    protected static function schemaFields(JsonSchema $schema): array
    {
        return array_merge([
            'audience_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255),
            'description' => $schema->string()->max(10000),
            'status' => $schema->string()->enum(['active', 'archived']),
        ], static::personaSchemaFields($schema), [
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ]);
    }

    protected static function rules(): array
    {
        return array_merge([
            'audience_id' => ['required', 'integer', 'min:1', 'exists:audiences,id'],
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ], static::personaValidationRules());
    }

    /** @param array<string, mixed> $validated */
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
        }

        $attributes = array_intersect_key($validated, array_flip([
            'name', 'description', 'status', 'persona_type', 'age_range', 'location', 'job_title', 'income_range',
            'education_level', 'family_status', 'research_confidence', 'last_reviewed_at', 'psychographics',
            'goals_and_motivations', 'pain_points', 'buying_behavior', 'product_ids',
        ]));
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

        return array_merge([
            'id' => $record->id,
            'enterprise_id' => $record->enterprise_id,
            'name' => $record->name,
            'description' => $record->description,
            'status' => $record->status,
        ], static::personaResult($record));
    }
}