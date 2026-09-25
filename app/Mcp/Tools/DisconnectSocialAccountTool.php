<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('disconnect-social-account')]
#[Description('Disconnect a social account by disabling its CR8OR connection record without exposing credentials.')]
class DisconnectSocialAccountTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return ['social_account_id' => $schema->integer()->min(1)->required(), 'agent_assignment_id' => $schema->integer()->min(1), 'agent_execution_id' => $schema->integer()->min(1), 'approval_request_id' => $schema->integer()->min(1)];
    }

    protected static function rules(): array
    {
        return ['social_account_id' => ['required', 'integer', 'min:1', 'exists:social_accounts,id'], 'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'], 'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'], 'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id']];
    }

    protected static function target(array $validated): ?Model
    {
        return SocialAccount::query()->with('enterprise')->findOrFail((int) $validated['social_account_id']);
    }

    protected static function enterprise(array $validated): Enterprise
    {
        $target = static::target($validated);
        if (! $target instanceof SocialAccount) {
            throw new \LogicException('Unexpected social account target.');
        }

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated */
    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['social_account_id' => $target?->getKey()];
    }

    /** @param array<string, mixed> $validated */
    /** @return array{0: string, 1: mixed} */
    // @phpstan-ignore missingType.iterableValue
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['delete', $target];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        if (! $target instanceof SocialAccount) {
            throw new \LogicException('Unexpected social account target.');
        }

        return $domain->disconnectSocialAccount($actor, $target);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof SocialAccount) {
            throw new \LogicException('Unexpected social account result.');
        }

        return ['id' => $record->id, 'enterprise_id' => $record->enterprise_id, 'status' => $record->status];
    }
}