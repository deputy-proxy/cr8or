<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-decisions')]
#[Description('List decisions visible in one authorized Enterprise, optionally filtered by type or title/summary and paginated.')]
class ListDecisionsTool extends DecisionCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'search' => $schema->string()->max(255),
            'type' => $schema->string()->enum(['operational', 'strategic']),
            'per_page' => $schema->integer()->min(1)->max(50),
            'page' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeDecision($request, 'mcp.decision.list', [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:operational,strategic'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], fn (User $actor, Enterprise $enterprise, array $input): array => [
            ['view', $enterprise],
            ['enterprise_id' => $enterprise->getKey()],
        ]);
    }
}
