<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
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
#[Description('Duplicate a generic or enterprise-specific Workflow definition without copying published versions or execution history.')]
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

        return $workflow->isEnterpriseSpecific()
            ? ['createForEnterprise', [Workflow::class, $workflow->enterprise]]
            : ['create', Workflow::class];
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

            $enterprise = Enterprise::query()->findOrFail((int) $validated['enterprise_id']);

            if (! $workflow->isAvailableForEnterprise($enterprise)) {
                throw new \LogicException('The Workflow is not available to the supplied Enterprise.');
            }

            $duplicate = $this->executeCapability($registry, $actor, [
                'workflow' => $workflow,
                'enterprise_id' => $enterprise->getKey(),
            ]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $duplicate->getKey(),
                    'enterprise_specific' => $duplicate->isEnterpriseSpecific(),
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