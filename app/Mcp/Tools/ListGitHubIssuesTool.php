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

#[Name('list-github-issues')]
#[Description('List open GitHub issue snapshots for an Enterprise the authenticated actor may view, including repository synchronization status and warnings.')]
final class ListGitHubIssuesTool extends GovernedCapabilityTool
{
    protected function expertSlug(): string
    {
        return 'operations';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'search' => $schema->string()->min(1)->max(255),
            'repository' => $schema->string()->min(1)->max(255),
            'per_page' => $schema->integer()->min(1)->max(50)->default(50),
            'page' => $schema->integer()->min(1)->default(1),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.integration.github.issue.list', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'search' => ['nullable', 'string', 'min:1', 'max:255'],
                'repository' => ['nullable', 'string', 'min:1', 'max:255'],
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
