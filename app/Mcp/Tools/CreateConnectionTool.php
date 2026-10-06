<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-connection')]
#[Description('Create an enterprise-scoped integration connection using a credential reference. Credential material itself must not be supplied to MCP.')]
class CreateConnectionTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'provider' => $schema->string()->min(1)->max(255)->required(),
            'external_account_id' => $schema->string()->min(1)->max(255),
            'credential_reference' => $schema->string()->min(1)->max(255)->required(),
            'status' => $schema->string()->min(1)->max(100),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    protected static function rules(): array
    {
        return [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'provider' => ['required', 'string', 'min:1', 'max:255'],
            'external_account_id' => ['nullable', 'string', 'min:1', 'max:255'],
            'credential_reference' => ['required', 'string', 'min:1', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,disabled,degraded,revoked'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
    }

    /** @param array<string, mixed> $validated */
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return [
            'enterprise_id' => $validated['enterprise_id'],
            'provider' => $validated['provider'],
            'external_account_id' => $validated['external_account_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $validated */
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['createForEnterprise', [IntegrationConnection::class, static::enterprise($validated)]];
    }

    /** @param array<string, mixed> $validated */
    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createIntegrationConnection($actor, static::enterprise($validated), $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof IntegrationConnection) {
            throw new \LogicException('Unexpected integration connection result.');
        }

        return [
            'id' => $record->id,
            'organization_id' => $record->organization_id,
            'enterprise_id' => $record->enterprise_id,
            'provider' => $record->provider,
            'external_account_id' => $record->external_account_id,
            'credential_reference' => $record->credential_reference,
            'status' => $record->status,
        ];
    }
}
