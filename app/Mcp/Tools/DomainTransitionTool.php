<?php

namespace App\Mcp\Tools;

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

abstract class DomainTransitionTool extends AuthorizedTool
{
    // @phpstan-ignore missingType.iterableValue
    abstract protected static function model(array $validated): Model;

    abstract protected static function enterprise(Model $target): Enterprise;

    abstract protected static function transition(User $actor, DomainResourceService $domain, Model $target, string $status): Model;

    protected static function schemaDescription(): string
    {
        return 'Target resource id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->description(static::schemaDescription())->required(),
            'status' => $schema->string()->min(1)->max(100)->description('Target lifecycle status.')->required(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, DomainResourceService $domain): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, static::operation(), function () use ($request, $authorization, $domain) {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'min:1'],
                'status' => ['required', 'string', 'min:1', 'max:100'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $target = static::model($validated);
            $enterprise = static::enterprise($target);

            $authorization->authorizeMutation(
                $actor,
                static::capability(),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['id' => $target->getKey()],
                ['update', $target],
            );

            $record = static::transition($actor, $domain, $target, $validated['status']);

            return Response::structured([
                'success' => true,
                'result' => ['id' => $record->getKey(), 'status' => $record->getAttribute('status')],
            ]);
        });
    }

    protected static function capability(): string
    {
        return static::operation();
    }

    protected static function operation(): string
    {
        return 'mcp.domain.transition';
    }
}
