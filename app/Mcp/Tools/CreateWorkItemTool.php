<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\McpCapabilityAuthorizer;
use App\Services\WorkItemService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-work-item')]
#[Description('Create a work item in an enterprise through the authorized CR8OR work capability.')]
class CreateWorkItemTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->description('Target enterprise id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Work item name.')->required(),
            'description' => $schema->string()->max(10000)->description('Optional work item description.'),
            'status' => $schema->string()->max(100)->description('Optional work item status.'),
            'project_id' => $schema->integer()->min(1)->description('Optional project id in the same enterprise.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, WorkItemService $workItems): Response|ResponseFactory
    {
        $validated = $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer', 'min:1', 'exists:projects,id'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ]);

        $actor = $request->user();

        if (! $actor instanceof User) {
            return Response::error('Authentication is required.');
        }

        /** @var Enterprise $enterprise */
        $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);

        $authorization->authorizeMutation(
            $actor,
            'work.create',
            $enterprise,
            $validated['agent_assignment_id'] ?? null,
            $validated['agent_execution_id'] ?? null,
            $validated['approval_request_id'] ?? null,
            [
                'enterprise_id' => $enterprise->getKey(),
                'project_id' => $validated['project_id'] ?? null,
            ],
            ['create', [WorkItem::class, $enterprise]],
        );

        $workItem = $workItems->create($actor, $enterprise, $validated);

        return Response::structured([
            'success' => true,
            'result' => [
                'id' => $workItem->getKey(),
                'enterprise_id' => $workItem->enterprise_id,
                'project_id' => $workItem->project_id,
                'name' => $workItem->name,
                'description' => $workItem->description,
                'status' => $workItem->status,
            ],
        ]);
    }
}
