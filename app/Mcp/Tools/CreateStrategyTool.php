<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\Objective;
use App\Models\Strategy;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use App\Services\StrategyService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-strategy')]
#[Description('Create a strategy under an authorized enterprise objective through CR8OR strategy capabilities.')]
class CreateStrategyTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'objective_id' => $schema->integer()->min(1)->description('Target objective id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Strategy name.')->required(),
            'description' => $schema->string()->max(10000)->description('Optional strategy description.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, StrategyService $strategies): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.strategy.create', function (string $correlationId) use ($request, $authorization, $strategies) {
            $validated = $request->validate([
                'objective_id' => ['required', 'integer', 'min:1', 'exists:objectives,id'],
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

            /** @var Objective $objective */
            $objective = Objective::query()->with('enterprise')->findOrFail($validated['objective_id']);
            /** @var Enterprise $enterprise */
            $enterprise = $objective->enterprise;

            $authorization->authorizeMutation(
                $actor,
                'strategy.create',
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['objective_id' => $objective->getKey()],
                ['create', [Strategy::class, $objective]],
            );

            $strategy = $strategies->create($actor, $objective, $validated);

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
