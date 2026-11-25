<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-tasks')]
#[Description('List open tasks in an Enterprise the authenticated actor may view, or explicitly across all Enterprises the actor may view. Defaults to todo and in-progress tasks; cross-Enterprise results are completeness-capped rather than silently truncated.')]
final class ListTaskTool extends GovernedCapabilityTool
{
    protected function expertSlug(): string
    {
        return 'operations';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required()->description('Enterprise execution context; all_accessible scope expands only to Enterprises the actor may view.'),
            'scope' => $schema->string()->enum(['enterprise', 'all_accessible'])->default('enterprise'),
            'max_items' => $schema->integer()->min(1)->max(5000)->default(1000)->description('Completeness limit for all_accessible scope. The operation fails rather than returning a partial cross-Enterprise result.'),
            'status' => $schema->string()->enum(TaskStatus::values())->description('Optional exact task status. Defaults to todo and in_progress.'),
            'priority' => $schema->string()->max(50),
            'project_id' => $schema->integer()->min(1),
            'initiative_id' => $schema->integer()->min(1),
            'search' => $schema->string()->min(1)->max(255),
            'per_page' => $schema->integer()->min(1)->max(50)->default(50),
            'page' => $schema->integer()->min(1)->default(1),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.work.task.list', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'scope' => ['nullable', 'string', 'in:enterprise,all_accessible'],
                'max_items' => ['nullable', 'integer', 'min:1', 'max:5000'],
                'status' => ['nullable', 'string', 'in:'.implode(',', TaskStatus::values())],
                'priority' => ['nullable', 'string', 'max:50'],
                'project_id' => ['nullable', 'integer', 'min:1', 'exists:projects,id'],
                'initiative_id' => ['nullable', 'integer', 'min:1', 'exists:initiatives,id'],
                'search' => ['nullable', 'string', 'min:1', 'max:255'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'page' => ['nullable', 'integer', 'min:1'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            return Response::structured([
                'success' => true,
                'result' => $this->executeCapability($registry, $actor, $validated),
            ]);
        });
    }
}
