<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\ExpertCapabilityService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('plan-marketing')]
#[Description('Create a structured marketing plan from authorized Enterprise, strategy and knowledge context.')]
final class PlanMarketingTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'target_context' => $schema->object()->description('Optional request-specific planning context.'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(
        Request $request,
        ExpertCapabilityService $experts,
    ): Response|ResponseFactory {
        return $this->executeWithErrors($request, 'mcp.marketing.plan', function () use ($request, $experts) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'target_context' => ['nullable', 'array'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Enterprise $enterprise */
            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);

            $result = $experts->execute(
                $actor,
                $enterprise,
                'marketing',
                'marketing.plan',
                $validated['target_context'] ?? [],
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
            );

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }
}
