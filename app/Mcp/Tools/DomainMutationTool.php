<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class DomainMutationTool extends AuthorizedTool
{
    /** @return array<string, mixed> */
    abstract protected static function schemaFields(JsonSchema $schema): array;

    /** @return array<string, array<int, mixed>> */
    abstract protected static function rules(): array;

    /** @param array<string, mixed> $validated */
    abstract protected static function enterprise(array $validated): Enterprise;

    /** @return array<string, mixed> */
    // @phpstan-ignore missingType.iterableValue
    abstract protected static function targetContext(array $validated, ?Model $target = null): array;

    /** @return array{0: string, 1: mixed} */
    // @phpstan-ignore missingType.iterableValue
    abstract protected static function humanAbility(array $validated, ?Model $target = null): array;

    /** @param array<string, mixed> $validated */
    abstract protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model;

    /** @return array<string, mixed> */
    abstract protected static function result(Model $record): array;

    public function schema(JsonSchema $schema): array
    {
        return static::schemaFields($schema);
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, DomainResourceService $domain, CapabilityRegistry $capabilities): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, static::operation(), function () use ($request, $authorization, $capabilities) {
            $validated = $request->validate(static::rules());
            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $target = static::target($validated);
            $enterprise = static::enterprise($validated);
            $humanAbility = static::humanAbility($validated, $target);

            $definition = $capabilities->forTool(static::class);

            $authorization->authorizeMutation(
                $actor,
                $definition->key,
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                static::targetContext($validated, $target),
                $humanAbility,
            );

            if ($definition->operation !== $capabilities->forTool(static::class)->operation) {
                throw new \LogicException('MCP capability operation mapping is inconsistent.');
            }

            $record = $capabilities->operationForTool(static::class)->execute($actor, $validated);

            return Response::structured(['success' => true, 'result' => static::result($record)]);
        });
    }

    /** @param array<string, mixed> $validated */
    /** @param array<string, mixed> $validated */
    public static function executeMutation(User $actor, array $validated): Model
    {
        $target = static::target($validated);

        return static::mutate($actor, app(DomainResourceService::class), $validated, $target);
    }

    /** @param array<string, mixed> $validated */
    protected static function target(array $validated): ?Model
    {
        return null;
    }

    protected static function capability(): string
    {
        return static::operation();
    }

    protected static function operation(): string
    {
        return 'mcp.domain.mutation';
    }
}