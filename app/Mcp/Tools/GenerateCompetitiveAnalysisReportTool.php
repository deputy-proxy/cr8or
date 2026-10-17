<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\Report;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('generate-competitive-analysis-report')]
#[Description('Generate an immutable, Enterprise-scoped competitive analysis report from selected competitor profile versions and their linked Knowledge evidence. Empty competitor_ids selects current versions.')]
final class GenerateCompetitiveAnalysisReportTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'competitor_ids' => $schema->array()->items($schema->integer()->min(1))->max(50)->description('Optional list of competitor profile version IDs. Empty or omitted selects current versions in this Enterprise.'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.competitor.report.generate', function () use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'competitor_ids' => ['sometimes', 'array', 'list', 'max:50'],
                'competitor_ids.*' => ['required', 'integer', 'min:1', 'distinct', 'exists:competitors,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $enterprise = Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
            $authorization->authorizeMutation(
                $actor,
                $this->capability($registry),
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['enterprise_id' => $enterprise->getKey(), 'competitor_ids' => $validated['competitor_ids'] ?? []],
                ['view', $enterprise],
            );

            /** @var Report $report */
            $report = $this->executeCapability($registry, $actor, [
                'enterprise' => $enterprise,
                'competitor_ids' => array_map('intval', $validated['competitor_ids'] ?? []),
            ]);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $report->getKey(),
                    'enterprise_id' => $report->enterprise_id,
                    'report_type' => $report->report_type,
                    'status' => $report->status,
                    'generated_at' => $report->getAttribute('generated_at') instanceof \DateTimeInterface ? $report->getAttribute('generated_at')->format(DATE_ATOM) : null,
                    'methodology_version' => $report->methodology_version,
                    'source_fingerprint' => $report->snapshot?->source_fingerprint,
                    'report' => $report->result_payload,
                ],
            ]);
        });
    }
}