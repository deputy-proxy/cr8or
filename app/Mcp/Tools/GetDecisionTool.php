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

#[Name('get-decision')]
#[Description('Retrieve one Decision belonging to the specified authorized Enterprise.')]
class GetDecisionTool extends DecisionCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'decision_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeDecision($request, 'mcp.decision.get', [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'decision_id' => ['required', 'integer', 'min:1'],
        ], fn (User $actor, Enterprise $enterprise, array $input): array => [
            ['view', $this->decision($enterprise, $input)],
            ['enterprise_id' => $enterprise->getKey(), 'decision_id' => (int) $input['decision_id']],
        ]);
    }
}
