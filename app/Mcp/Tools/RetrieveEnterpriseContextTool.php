<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('retrieve-enterprise-context')]
#[Description('Retrieve the authorized, canonical Enterprise context used by downstream Agent and Expert execution.')]
final class RetrieveEnterpriseContextTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'correlation_id' => $schema->string()->max(255),
            'idempotency_key' => $schema->string()->max(255),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.enterprise.context.retrieve', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'correlation_id' => ['nullable', 'string', 'max:255'],
                'idempotency_key' => ['nullable', 'string', 'max:255'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new \Illuminate\Auth\AuthenticationException;
            }

            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);

            $result = $this->executeCapability($registry, $actor, [
                'enterprise' => $enterprise,
                ...$validated,
            ]);

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }
}