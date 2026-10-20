<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DefinesPersonaSchema;
use App\Models\Enterprise;
use App\Models\Persona;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-persona')]
#[Description('Create a client persona under an authorized enterprise, including structured persona attributes and optional same-enterprise product associations. Persona fields describe an archetype, not an identifiable customer.')]
class CreatePersonaTool extends DomainMutationTool
{
    use DefinesPersonaSchema;

    protected static function schemaFields(JsonSchema $schema): array
    {
        return array_merge([
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255)->required(),
            'description' => $schema->string()->max(10000),
        ], static::personaSchemaFields($schema), [
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ]);
    }

    protected static function rules(): array
    {
        return array_merge([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ], static::personaValidationRules());
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
        return ['create', [Persona::class, static::enterprise($validated)]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createPersona($actor, static::enterprise($validated), $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Persona) {
            throw new \LogicException('Unexpected persona result.');
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