<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-knowledge-item')]
#[Description('Create authoritative Enterprise-scoped Knowledge content through the governed Capability boundary.')]
class CreateKnowledgeItemTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'title' => $schema->string()->min(1)->max(255)->required(),
            'type' => $schema->string()->max(100),
            'summary' => $schema->string()->max(5000),
            'content' => $schema->string()->min(1)->max(50000)->required(),
            'context_snapshot' => $schema->object(),
            'correlation_id' => $schema->string()->max(255),
            'idempotency_key' => $schema->string()->min(1)->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.knowledge.item.create', function () use ($request) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            return Response::structured([
                'success' => true,
                'result' => $this->executeCapability(
                    app(CapabilityRegistry::class),
                    $actor,
                    $request->validate([
                        'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                        'title' => ['required', 'string', 'min:1', 'max:255'],
                        'type' => ['nullable', 'string', 'max:100'],
                        'summary' => ['nullable', 'string', 'max:5000'],
                        'content' => ['required', 'string', 'min:1', 'max:50000'],
                        'context_snapshot' => ['nullable', 'array'],
                        'correlation_id' => ['nullable', 'string', 'max:255'],
                        'idempotency_key' => ['nullable', 'string', 'min:1', 'max:255'],
                    ]),
                ),
            ]);
        });
    }
}
