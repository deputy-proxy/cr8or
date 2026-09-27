<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-marketing-strategy')]
#[Description('Create a marketing strategy under an authorized enterprise through the governed CR8OR marketing strategy capability.')]
class CreateMarketingStrategyTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->description('Target enterprise id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Marketing strategy name.')->required(),
            'description' => $schema->string()->max(10000)->description('Optional strategy description.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.strategy.create', function () use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $enterprise = Enterprise::query()->findOrFail((int) $validated['enterprise_id']);

            $authorization->authorizeMutation(
                $actor,
                $this->capability($registry),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['enterprise_id' => $enterprise->getKey()],
                ['create', [MarketingStrategy::class, $enterprise]],
            );

            /** @var MarketingStrategy $strategy */
            $strategy = $this->executeCapability(
                $registry,
                $actor,
                ['enterprise' => $enterprise, ...$validated],
            );

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $strategy->getKey(),
                    'enterprise_id' => $strategy->enterprise_id,
                    'name' => $strategy->name,
                    'description' => $strategy->description,
                    'status' => $strategy->status,
                ],
            ]);
        });
    }
}
