<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\WorkItem;
use App\Services\McpCapabilityAuthorizer;
use App\Services\WorkItemService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use LogicException;

#[Name('update-work-item')]
#[Description('Update an existing work item through the authorized CR8OR work capability.')]
#[IsIdempotent]
class UpdateWorkItemTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'work_item_id' => $schema->integer()->min(1)->description('Work item id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Replacement work item name.'),
            'description' => $schema->string()->max(10000)->description('Replacement work item description.'),
            'status' => $schema->string()->max(100)->description('Replacement work item status.'),
            'project_id' => $schema->integer()->min(1)->description('Replacement project id in the same enterprise.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, WorkItemService $workItems): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.work.update', function (string $correlationId) use ($request, $authorization, $workItems) {
            $validated = $request->validate([
                'work_item_id' => ['required', 'integer', 'min:1', 'exists:work_items,id'],
                'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'status' => ['sometimes', 'string', 'max:100'],
                'project_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:projects,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var WorkItem $workItem */
            $workItem = WorkItem::query()->findOrFail($validated['work_item_id']);
            $enterprise = $workItem->enterprise;

            $authorization->authorizeMutation(
                $actor,
                'work.update',
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['work_item_id' => $workItem->getKey()],
                ['update', $workItem],
            );

            $attributes = array_intersect_key($validated, array_flip(['name', 'description', 'status', 'project_id']));

            if ($attributes === []) {
                throw new LogicException('At least one mutable work item field is required.');
            }

            $workItem = $workItems->update($actor, $workItem, $attributes);

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
        });
    }
}
