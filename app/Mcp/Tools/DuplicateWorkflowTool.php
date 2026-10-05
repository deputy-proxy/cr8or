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

#[Name('mcp_workflow_duplicate')]
#[Description('Duplicate an enterprise-scoped Workflow definition without copying published versions or execution history.')]
class DuplicateWorkflowTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'workflow_id' => $schema->integer()->min(1)->required(),
        ];
    }

    protected function humanAbility(User $actor, array $input): ?array
    {
        $workflow = Workflow::query()->findOrFail((int) $input['workflow_id']);

        return ['createForEnterprise', $workflow->enterprise];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.workflow.duplicate', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'workflow_id' => ['required', 'integer', 'min:1', 'exists:workflows,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $workflow = Workflow::query()->findOrFail((int) $validated['workflow_id']);

            if ((int) $validated['enterprise_id'] !== (int) $workflow->enterprise_id) {
                throw new \LogicException('The Workflow does not belong to the supplied Enterprise.');
            }

            $duplicate = $this->executeCapability($registry, $actor, [
                'workflow' => $workflow,
                'enterprise_id' => $workflow->enterprise_id,
            ]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $duplicate->getKey(),
                    'enterprise_id' => $duplicate->enterprise_id,
                    'name' => $duplicate->name,
                    'canonical_key' => $duplicate->canonical_key,
                    'status' => $duplicate->status,
                    'stage_count' => $duplicate->stages()->count(),
                    'published_version_id' => $duplicate->published_version_id,
                ],
            ]);
        });
    }
}
