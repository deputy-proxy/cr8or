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

#[Name('get-workflow-execution')]
#[Description('Inspect durable state for an enterprise-scoped WorkflowExecution.')]
class GetWorkflowExecutionTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'workflow_execution_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.inspect', function () use ($request, $registry) {
            $v = $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'workflow_execution_id' => ['required', 'integer', 'min:1', 'exists:workflow_executions,id']]);
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            return Response::structured(['success' => true, 'result' => $this->executeCapability($registry, $actor, $v)]);
        });
    }
}