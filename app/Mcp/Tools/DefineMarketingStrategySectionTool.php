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

#[Name('define-marketing-strategy-section')]
#[Description('Define one deterministic section of a marketing strategy from authorized enterprise context.')]
class DefineMarketingStrategySectionTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'section_key' => $schema->string()->min(1)->max(100)->required(),
            'context' => $schema->object(),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.strategy.section.define', function () use ($request, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'section_key' => ['required', 'string', 'min:1', 'max:100'],
                'context' => ['nullable', 'array'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $result = $this->executeCapability($registry, $actor, $validated);

            return Response::structured(['success' => true, 'result' => $result]);
        });
    }
}