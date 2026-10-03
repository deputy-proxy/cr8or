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

#[Name('mcp_workflow_create')]
#[Description('Create an enterprise-scoped deterministic Workflow definition.')]
class CreateWorkflowTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255)->required(),
            'canonical_key' => $schema->string()->min(1)->max(150),
            'purpose' => $schema->string()->max(10000),
            'stages' => $schema->array()->min(1)->required(),
            'execution_policy' => $schema->object(),
            'completion_criteria' => $schema->object(),
            'project_id' => $schema->integer()->min(1),
            'task_id' => $schema->integer()->min(1),
            'work_item_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.create', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'canonical_key' => ['nullable', 'string', 'min:1', 'max:150', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
                'purpose' => ['nullable', 'string', 'max:10000'],
                'stages' => ['required', 'array', 'min:1'],
                'stages.*.key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
                'stages.*.name' => ['nullable', 'string', 'max:255'],
                'stages.*.sequence' => ['nullable', 'integer', 'min:0'],
                'stages.*.dependencies' => ['nullable', 'array'],
                'stages.*.expert_slugs' => ['required', 'array', 'min:1'],
                'stages.*.capability_slugs' => ['required', 'array', 'min:1'],
                'stages.*.input_contract' => ['nullable', 'array'],
                'stages.*.output_contract' => ['nullable', 'array'],
                'stages.*.repeatable' => ['nullable', 'boolean'],
                'stages.*.completion_criteria' => ['nullable', 'array'],
                'execution_policy' => ['nullable', 'array'],
                'completion_criteria' => ['nullable', 'array'],
                'project_id' => ['nullable', 'integer', 'min:1'],
                'task_id' => ['nullable', 'integer', 'min:1'],
                'work_item_id' => ['nullable', 'integer', 'min:1'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $workflow = $this->executeCapability($registry, $actor, $validated);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $workflow->getKey(),
                    'enterprise_id' => $workflow->enterprise_id,
                    'name' => $workflow->name,
                    'status' => $workflow->status,
                    'stage_count' => $workflow->stages()->count(),
                ],
            ]);
        });
    }
}