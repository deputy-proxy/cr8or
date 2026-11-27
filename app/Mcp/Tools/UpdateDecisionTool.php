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

#[Name('update-decision')]
#[Description('Update only the title, summary or rationale of a Decision. Enterprise ownership and all historical fields remain immutable.')]
class UpdateDecisionTool extends DecisionCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'decision_id' => $schema->integer()->min(1)->required(),
            'title' => $schema->string()->min(1)->max(255),
            'summary' => $schema->string()->min(1)->max(20000),
            'rationale' => $schema->string()->max(20000)->nullable(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeDecision($request, 'mcp.decision.update', [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'decision_id' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'summary' => ['sometimes', 'string', 'min:1', 'max:20000'],
            'rationale' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ], fn (User $actor, Enterprise $enterprise, array $input): array => [
            ['update', $this->decision($enterprise, $input)],
            ['enterprise_id' => $enterprise->getKey(), 'decision_id' => (int) $input['decision_id']],
        ]);
    }
}
