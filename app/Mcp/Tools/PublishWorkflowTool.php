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

#[Name('publish-workflow')]
#[Description('Publish a new immutable WorkflowVersion for an enterprise Workflow.')]
class PublishWorkflowTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['enterprise_id' => $schema->integer()->min(1)->required(), 'workflow_id' => $schema->integer()->min(1)->required(), 'idempotency_key' => $schema->string()->min(1)->max(128)->required()];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.publish', function () use ($request, $registry) {
            $v = $request->validate(['enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'], 'workflow_id' => ['required', 'integer', 'min:1', 'exists:workflows,id'], 'idempotency_key' => ['required', 'string', 'min:1', 'max:128']]);
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }
            $version = $this->executeCapability($registry, $actor, $v);

            return Response::structured(['success' => true, 'result' => ['workflow_id' => $version->workflow_id, 'workflow_version_id' => $version->id, 'version' => $version->version, 'status' => $version->status, 'published_at' => $version->published_at?->toIso8601String()]]);
        });
    }
}