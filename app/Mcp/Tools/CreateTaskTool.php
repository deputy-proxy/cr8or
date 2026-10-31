<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\Task;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-task')]
#[Description('Create a task in an enterprise through the authorized CR8OR work capability.')]
class CreateTaskTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->description('Target enterprise id.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Task name.')->required(),
            'description' => $schema->string()->max(10000)->description('Optional task description.'),
            'status' => $schema->string()->max(100)->description('Optional task status.'),
            'parent_task_id' => $schema->integer()->min(1)->description('Optional parent Task in the same Project or Initiative and Enterprise.'),
            'priority' => $schema->string()->max(50)->description('Optional task priority.'),
            'due_at' => $schema->string()->description('Optional ISO 8601 due timestamp.'),
            'project_id' => $schema->integer()->min(1)->description('Optional Project id in the same enterprise. Cannot be combined with initiative_id.'),
            'initiative_id' => $schema->integer()->min(1)->description('Optional Initiative id in the same enterprise. Cannot be combined with project_id.'),
            'agent_assignment_id' => $schema->integer()->min(1)->description('Required with agent_execution_id for an Agent-backed invocation.'),
            'agent_execution_id' => $schema->integer()->min(1)->description('Required with agent_assignment_id for an Agent-backed invocation.'),
            'approval_request_id' => $schema->integer()->min(1)->description('Approved request required when the Agent capability requires approval.'),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.work.task.create', function (string $correlationId) use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'status' => ['nullable', 'string', 'max:100'],
                'parent_task_id' => ['nullable', 'integer', 'min:1', 'exists:tasks,id'],
                'priority' => ['nullable', 'string', 'max:50'],
                'due_at' => ['nullable', 'date'],
                'project_id' => ['nullable', 'integer', 'min:1', 'exists:projects,id'],
                'initiative_id' => ['nullable', 'integer', 'min:1', 'exists:initiatives,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $projectId = $validated['project_id'] ?? null;
            $initiativeId = $validated['initiative_id'] ?? null;
            if ($projectId !== null && $initiativeId !== null) {
                throw ValidationException::withMessages([
                    'project_id' => 'A Task can belong to a Project or Initiative, not both.',
                ]);
            }

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Enterprise $enterprise */
            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);

            $authorization->authorizeMutation(
                $actor,
                $this->capability($registry),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                [
                    'enterprise_id' => $enterprise->getKey(),
                    'project_id' => $validated['project_id'] ?? null,
                    'initiative_id' => $validated['initiative_id'] ?? null,
                ],
                ['createForEnterprise', [Task::class, $enterprise]],
            );

            $task = $this->executeCapability($registry, $actor, ['enterprise' => $enterprise, ...$validated]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $task->getKey(),
                    'enterprise_id' => $task->enterprise_id,
                    'project_id' => $task->project_id,
                    'initiative_id' => $task->initiative_id,
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
