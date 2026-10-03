<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('verify-marketing-graph')]
#[Description('Verify that the autonomous marketing workflow created the required enterprise-scoped graph and that planned assets remain pending.')]
final class VerifyMarketingGraphTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'marketing_strategy_id' => $schema->integer()->min(1)->required(),
            'audience_ids' => $schema->array()->required(),
            'campaign_ids' => $schema->array()->required(),
            'content_series_ids' => $schema->array()->required(),
            'content_item_ids' => $schema->array()->required(),
            'script_ids' => $schema->array()->required(),
            'asset_ids' => $schema->array()->required(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.graph.verify', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'marketing_strategy_id' => ['required', 'integer', 'min:1'],
                'audience_ids' => ['required', 'array'], 'audience_ids.*' => ['integer', 'min:1'],
                'campaign_ids' => ['required', 'array'], 'campaign_ids.*' => ['integer', 'min:1'],
                'content_series_ids' => ['required', 'array'], 'content_series_ids.*' => ['integer', 'min:1'],
                'content_item_ids' => ['required', 'array'], 'content_item_ids.*' => ['integer', 'min:1'],
                'script_ids' => ['required', 'array'], 'script_ids.*' => ['integer', 'min:1'],
                'asset_ids' => ['required', 'array'], 'asset_ids.*' => ['integer', 'min:1'],
                'agent_assignment_id' => ['sometimes', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['sometimes', 'integer', 'min:1', 'exists:agent_executions,id'],
            ]);
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }
            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);
            $result = $this->executeCapability($registry, $actor, ['enterprise' => $enterprise, ...$validated]);

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }
}
