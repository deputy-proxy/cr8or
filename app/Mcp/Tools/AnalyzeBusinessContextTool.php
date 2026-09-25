<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use App\Services\ExpertCapabilityService;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('analyze-business-context')]
#[Description('Analyze authorized Enterprise, strategy, work and financial context with the Business Analysis Expert.')]
final class AnalyzeBusinessContextTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'target_context' => $schema->object()->description('Optional request-specific context.'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(
        Request $request,
        McpCapabilityAuthorizer $authorization,
        ExpertCapabilityService $experts,
    ): Response|ResponseFactory {
        return $this->executeWithErrors($request, 'mcp.business.analysis', function () use ($request, $authorization, $experts) {
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

            $authorization->authorizeCapability(
                $actor,
                'business.analysis',
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['enterprise_id' => $enterprise->getKey(), ...($validated['target_context'] ?? [])],
            );

            $result = $experts->execute(
                $actor,
                $enterprise,
                'business-analysis',
                'business.analysis',
                $validated['target_context'] ?? [],
            );

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }
}
