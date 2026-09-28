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

#[Name('create-knowledge-index')]
#[Description('Create or rebuild the authorized derived Knowledge index through the governed Capability boundary.')]
class CreateKnowledgeIndexTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'knowledge_item_id' => $schema->integer()->min(1)->required(),
            'correlation_id' => $schema->string()->max(255),
            'idempotency_key' => $schema->string()->min(1)->max(255),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.knowledge.index.create', function () use ($request) {
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
                        'knowledge_item_id' => ['required', 'integer', 'min:1', 'exists:knowledge_items,id'],
                        'correlation_id' => ['nullable', 'string', 'max:255'],
                        'idempotency_key' => ['nullable', 'string', 'min:1', 'max:255'],
                    ]),
                ),
            ]);
        });
    }
}
