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

#[Name('start-workflow')]
#[Description('Start a published deterministic Workflow without creating an AgentExecution.')]
class StartWorkflowTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'workflow_id' => $schema->integer()->min(1)->required(), 'input' => $schema->object(), 'idempotency_key' => $schema->string()->min(1)->max(128)->required(), 'correlation_id' => $schema->string()->max(255)];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.start', function () use ($request, $registry) {
            $v = $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'workflow_id' => ['required', 'integer', 'min:1', 'exists:workflows,id'], 'input' => ['nullable', 'array'], 'idempotency_key' => ['required', 'string', 'min:1', 'max:128'], 'correlation_id' => ['nullable', 'string', 'max:255']]);
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }
            $e = $this->executeCapability($registry, $actor, $v);

            return Response::structured(['success' => true, 'result' => ['workflow_execution_id' => $e->id, 'workflow_id' => $e->workflow_id, 'workflow_version_id' => $e->workflow_version_id, 'status' => $e->status, 'current_stage_key' => $e->current_stage_key, 'correlation_id' => $e->correlation_id, 'idempotency_key' => $e->idempotency_key]]);
        });
    }
}