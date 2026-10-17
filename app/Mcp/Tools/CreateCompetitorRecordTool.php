<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Competitor;
use App\Services\McpCapabilityAuthorizer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-competitor-record')]
#[Description('Create or revise an enterprise-scoped competitor profile without generating a report. Reusing the same exact name creates a successor version and preserves history. Use create-competitor for the combined profile-and-report action.')]
final class CreateCompetitorRecordTool extends CreateCompetitorTool
{
    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.competitor.create', function () use ($request, $authorization, $registry) {
            $result = $this->performCreation($request, $authorization, $registry);

            if (! $result instanceof Competitor) {
                throw new \LogicException('The create-competitor-record capability must return a Competitor.');
            }

            return Response::structured([
                'success' => true,
                'result' => $this->serializeCompetitor($result),
            ]);
        });
    }
}
