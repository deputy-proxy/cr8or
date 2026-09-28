<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('retrieve-knowledge')]
#[Description('Retrieve bounded, authorized Knowledge using lexical, semantic or hybrid retrieval with provenance.')]
class RetrieveKnowledgeTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'query' => $schema->string()->max(10000),
            'objective' => $schema->string()->max(10000),
            'mode' => $schema->string()->enum(['lexical', 'semantic', 'hybrid'])->description('Retrieval mode.')->required(),
            'limit' => $schema->integer()->min(1)->max(50),
            'minimum_relevance' => $schema->number()->min(0)->max(1),
            'correlation_id' => $schema->string()->max(255),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.knowledge.retrieve', function (string $correlationId) use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'query' => ['nullable', 'string', 'max:10000'],
                'objective' => ['nullable', 'string', 'max:10000'],
                'mode' => ['required', 'string', 'in:lexical,semantic,hybrid'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
                'minimum_relevance' => ['nullable', 'numeric', 'min:0', 'max:1'],
                'correlation_id' => ['nullable', 'string', 'max:255'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
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
                $validated['agent_execution_id'] ?? null,
                null,
                ['enterprise_id' => $enterprise->getKey(), 'mode' => $validated['mode']],
            );

            $input = [
                'enterprise' => $enterprise,
                'query' => $validated['query'] ?? null,
                'objective' => $validated['objective'] ?? null,
                'mode' => $validated['mode'],
                'limit' => $validated['limit'] ?? 20,
                'correlation_id' => $validated['correlation_id'] ?? $correlationId,
            ];

            if (array_key_exists('minimum_relevance', $validated) && $validated['minimum_relevance'] !== null) {
                $input['minimum_relevance'] = $validated['minimum_relevance'];
            }

            return Response::structured([
                'success' => true,
                'result' => $this->executeCapability($registry, $actor, $input),
            ]);
        });
    }
}