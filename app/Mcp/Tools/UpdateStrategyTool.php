<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\Strategy;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use LogicException;

#[Name('update-strategy')]
#[Description('Update an existing strategy through the authorized CR8OR strategy capability.')]
#[IsIdempotent]
class UpdateStrategyTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'strategy_id' => $schema->integer()->min(1)->description('Strategy id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Replacement strategy name.'),
            'description' => $schema->string()->max(10000)->description('Replacement strategy description.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.strategy.update', function (string $correlationId) use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'strategy_id' => ['required', 'integer', 'min:1', 'exists:strategies,id'],
                'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Strategy $strategy */
            $strategy = Strategy::query()->with('objective.enterprise')->findOrFail($validated['strategy_id']);
            /** @var Enterprise $enterprise */
            $enterprise = $strategy->objective->enterprise;

            $authorization->authorizeMutation(
                $actor,
                $this->capability($registry),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['strategy_id' => $strategy->getKey()],
                ['update', $strategy],
            );

            $attributes = array_intersect_key($validated, array_flip(['name', 'description']));

            if ($attributes === []) {
                throw new LogicException('At least one mutable strategy field is required.');
            }

            $strategy = $this->executeCapability($registry, $actor, ['strategy' => $strategy, ...$validated, 'attributes' => $attributes]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $strategy->getKey(),
                    'objective_id' => $strategy->objective_id,
                    'name' => $strategy->name,
                    'description' => $strategy->description,
                ],
            ]);
        });
    }
}