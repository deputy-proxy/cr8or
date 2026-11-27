<?php

namespace App\Mcp\Tools;

use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-decision')]
#[Description('Record a Decision in an authorized Enterprise. The actor and decision timestamp are assigned by CR8OR; historical fields cannot be supplied by the caller.')]
class CreateDecisionTool extends DecisionCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'type' => $schema->string()->enum(['operational', 'strategic']),
            'objective_id' => $schema->integer()->min(1),
            'strategy_id' => $schema->integer()->min(1),
            'initiative_id' => $schema->integer()->min(1),
            'project_id' => $schema->integer()->min(1),
            'task_id' => $schema->integer()->min(1),
            'title' => $schema->string()->min(1)->max(255)->required(),
            'summary' => $schema->string()->min(1)->max(20000)->required(),
            'rationale' => $schema->string()->max(20000),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeDecision($request, 'mcp.decision.create', [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'type' => ['nullable', 'string', 'in:operational,strategic'],
            'objective_id' => ['nullable', 'integer', 'min:1'],
            'strategy_id' => ['nullable', 'integer', 'min:1'],
            'initiative_id' => ['nullable', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'task_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'summary' => ['required', 'string', 'min:1', 'max:20000'],
            'rationale' => ['nullable', 'string', 'max:20000'],
        ], fn (User $actor, Enterprise $enterprise, array $input): array => [
            ['createForEnterprise', [Decision::class, $enterprise]],
            ['enterprise_id' => $enterprise->getKey()],
        ]);
    }
}
