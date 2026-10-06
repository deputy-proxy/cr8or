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

#[Name('mcp_workflow_resume')]
#[Description('Continue an existing deterministic WorkflowExecution without Agent reasoning. Input may supply requested stage fields from the caller.')]
class ResumeWorkflowExecutionTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'workflow_execution_id' => $schema->integer()->min(1)->required(), 'continuation_token' => $schema->string()->min(1)->required(), 'input' => $schema->object()];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.resume', function () use ($request, $registry) {
            $v = $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'workflow_execution_id' => ['required', 'integer', 'min:1', 'exists:workflow_executions,id'], 'continuation_token' => ['required', 'string', 'min:1'], 'input' => ['nullable', 'array']]);
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }
            $e = $this->executeCapability($registry, $actor, $v);

            return Response::structured(['success' => true, 'result' => ['workflow_execution_id' => $e->id, 'workflow_id' => $e->workflow_id, 'workflow_version_id' => $e->workflow_version_id, 'status' => $e->status, 'current_stage_key' => $e->current_stage_key, 'continuation_token' => $e->continuation_token]]);
        });
    }
}