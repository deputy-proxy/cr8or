<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use LogicException;

#[Name('mcp_workflow_update')]
#[Description('Update an existing enterprise-scoped deterministic Workflow definition.')]
#[IsIdempotent]
class UpdateWorkflowTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'workflow_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255),
            'canonical_key' => $schema->string()->min(1)->max(150),
            'purpose' => $schema->string()->max(10000),
            'stages' => $schema->array()->min(1),
            'execution_policy' => $schema->object(),
            'completion_criteria' => $schema->object(),
            'project_id' => $schema->integer()->min(1),
            'task_id' => $schema->integer()->min(1),
            'work_item_id' => $schema->integer()->min(1),
        ];
    }

    protected function humanAbility(User $actor, array $input): ?array
    {
        $workflow = Workflow::query()->findOrFail((int) $input['workflow_id']);

        return ['update', $workflow];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.update', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'workflow_id' => ['required', 'integer', 'min:1', 'exists:workflows,id'],
                'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                'canonical_key' => ['sometimes', 'nullable', 'string', 'min:1', 'max:150', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
                'purpose' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'stages' => ['sometimes', 'array', 'min:1'],
                'stages.*.key' => ['required_with:stages', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
                'stages.*.name' => ['nullable', 'string', 'max:255'],
                'stages.*.instruction' => ['nullable', 'string', 'max:10000'],
                'stages.*.sequence' => ['nullable', 'integer', 'min:1'],
                'stages.*.dependencies' => ['nullable', 'array'],
                'stages.*.expert_slugs' => ['required_with:stages', 'array', 'min:1'],
                'stages.*.capability_slugs' => ['required_with:stages', 'array', 'min:1'],
                'stages.*.input_contract' => ['nullable', 'array'],
                'stages.*.output_contract' => ['nullable', 'array'],
                'stages.*.repeatable' => ['nullable', 'boolean'],
                'stages.*.completion_criteria' => ['nullable', 'array'],
                'execution_policy' => ['sometimes', 'nullable', 'array'],
                'completion_criteria' => ['sometimes', 'nullable', 'array'],
                'project_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'task_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'work_item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            if ((int) $validated['enterprise_id'] !== (int) Workflow::query()->whereKey($validated['workflow_id'])->value('enterprise_id')) {
                throw new LogicException('The Workflow does not belong to the supplied Enterprise.');
            }

            $workflow = $this->executeCapability($registry, $actor, $validated);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $workflow->getKey(),
                    'enterprise_id' => $workflow->enterprise_id,
                    'name' => $workflow->name,
                    'canonical_key' => $workflow->canonical_key,
                    'purpose' => $workflow->purpose,
                    'status' => $workflow->status,
                    'stage_count' => $workflow->stages()->count(),
                    'published_version_id' => $workflow->published_version_id,
                ],
            ]);
        });
    }
}