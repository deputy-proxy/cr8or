<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\User;
use App\Operations\RetrieveMemory;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('retrieve-memory')]
#[Description('Retrieve bounded, Enterprise-scoped Agent Memory through the governed memory.retrieve capability.')]
class RetrieveMemoryTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'agent_descriptor_id' => $schema->integer()->min(1)->required(),
            'correlation_id' => $schema->string()->max(255),
            'agent_assignment_id' => $schema->integer()->min(1),
            'topic' => $schema->string()->max(200),
            'relevant_after' => $schema->string()->max(100),
            'episodic_limit' => $schema->integer()->min(1)->max(50),
            'semantic_status' => $schema->string()->max(50),
            'semantic_limit' => $schema->integer()->min(1)->max(100),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.memory.retrieve', function (string $correlationId) use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'agent_descriptor_id' => ['required', 'integer', 'min:1', 'exists:agent_descriptors,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'topic' => ['nullable', 'string', 'max:200'],
                'relevant_after' => ['nullable', 'date'],
                'episodic_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
                'semantic_status' => ['nullable', 'string', 'in:active,disputed,superseded,archived'],
                'semantic_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
                'correlation_id' => ['nullable', 'string', 'max:255'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Enterprise $enterprise */
            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);
            $definition = $this->definition($registry);

            $authorization->authorizeCapability(
                $actor,
                $definition->key,
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                null,
                null,
                ['enterprise_id' => $enterprise->getKey(), 'agent_descriptor_id' => $validated['agent_descriptor_id']],
            );

            return Response::structured([
                'success' => true,
                'result' => app(RetrieveMemory::class)->execute($actor, [
                    ...$validated,
                    'enterprise' => $enterprise,
                    'correlation_id' => $validated['correlation_id'] ?? $correlationId,
                ]),
            ]);
        });
    }
}