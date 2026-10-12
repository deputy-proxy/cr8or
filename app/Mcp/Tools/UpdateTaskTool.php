<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Task;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use LogicException;

#[Name('update-task')]
#[Description('Update an existing task through the authorized CR8OR work capability.')]
#[IsIdempotent]
class UpdateTaskTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->min(1)->description('Task id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Replacement task name.'),
            'description' => $schema->string()->max(10000)->description('Replacement task description.'),
            'status' => $schema->string()->max(100)->description('Replacement task status.'),
            'parent_task_id' => $schema->integer()->min(1)->description('Optional parent Task.'),
            'priority' => $schema->string()->max(50)->description('Task priority.'),
            'due_at' => $schema->string()->description('ISO 8601 due timestamp.'),
            'project_id' => $schema->integer()->min(1)->description('Replacement project id in the same enterprise.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.work.task.update', function (string $correlationId) use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'task_id' => ['required', 'integer', 'min:1', 'exists:tasks,id'],
                'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'status' => ['sometimes', 'string', 'max:100'],
                'parent_task_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:tasks,id'],
                'priority' => ['sometimes', 'string', 'max:50'],
                'due_at' => ['sometimes', 'nullable', 'date'],
                'project_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:projects,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Task $task */
            $task = Task::query()->findOrFail($validated['task_id']);
            $enterprise = $task->enterprise;

            $authorization->authorizeMutation(
                $actor,
                $this->capability($registry),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['task_id' => $task->getKey()],
                ['update', $task],
            );

            $attributes = array_intersect_key($validated, array_flip(['name', 'description', 'status', 'project_id', 'parent_task_id', 'priority', 'due_at']));

            if ($attributes === []) {
                throw new LogicException('At least one mutable task field is required.');
            }

            $task = $this->executeCapability($registry, $actor, ['enterprise' => $enterprise, 'task' => $task, ...$validated, 'attributes' => $attributes, 'target_context' => ['task_id' => $task->getKey()]]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $task->getKey(),
                    'enterprise_id' => $task->enterprise_id,
                    'project_id' => $task->project_id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'status' => $task->status,
                    'parent_task_id' => $task->parent_task_id,
                    'priority' => $task->priority,
                    'due_at' => $task->due_at,
                ],
            ]);
        });
    }
}