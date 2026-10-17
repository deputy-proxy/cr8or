<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Report;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-competitor')]
#[Description('Create or revise an enterprise-scoped competitor profile using structured product, pricing, market, channel, reputation, digital-presence, and evidence fields, then generate and return an immutable competitive-analysis report. Reusing the same exact name creates a new version; history is preserved.')]
class CreateCompetitorTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->description('Target enterprise ID; access is verified server-side.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Canonical competitor name.')->required(),
            'website' => $schema->string()->max(255)->description('Optional absolute website URL including scheme.'),
            'positioning' => $schema->string()->max(10000)->description('Optional positioning summary.'),
            'strengths' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50)->description('Up to 50 known strengths; do not invent facts.'),
            'weaknesses' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50)->description('Up to 50 known weaknesses; do not invent facts.'),
            'products' => self::entryList($schema, [
                'name' => $schema->string()->min(1)->max(255)->required(),
                'description' => $schema->string()->max(5000),
                'category' => $schema->string()->max(255),
                'features' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50),
                'target_segment' => $schema->string()->max(500),
                'differentiators' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50),
                'pricing_reference' => $schema->string()->max(255),
            ], 'Structured product entries.'),
            'target_segments' => self::entryList($schema, [
                'name' => $schema->string()->min(1)->max(255)->required(),
                'description' => $schema->string()->max(5000),
                'industry' => $schema->string()->max(255),
                'demographics' => $schema->string()->max(2000),
                'needs' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50),
                'use_cases' => $schema->array()->items($schema->string()->min(1)->max(500))->max(50),
            ], 'Structured target segment entries.'),
            'pricing' => self::entryList($schema, [
                'model' => $schema->string()->min(1)->max(100)->required(),
                'product_name' => $schema->string()->max(255),
                'amount' => $schema->number()->min(0),
                'minimum_amount' => $schema->number()->min(0),
                'maximum_amount' => $schema->number()->min(0),
                'currency' => $schema->string()->min(3)->max(3),
                'billing_interval' => $schema->string()->max(100),
                'terms' => $schema->string()->max(2000),
                'package' => $schema->string()->max(255),
            ], 'Structured pricing entries. Prices are recorded values, not necessarily comparable.'),
            'markets' => self::entryList($schema, [
                'geography' => $schema->string()->min(1)->max(255)->required(),
                'industries' => $schema->array()->items($schema->string()->min(1)->max(255))->max(50),
                'languages' => $schema->array()->items($schema->string()->min(1)->max(100))->max(50),
                'notes' => $schema->string()->max(2000),
            ], 'Structured market entries.'),
            'differentiators' => self::entryList($schema, [
                'claim' => $schema->string()->min(1)->max(2000)->required(),
                'claim_type' => $schema->string()->enum(['competitor_claim', 'observed_fact', 'verified_fact'])->required(),
                'category' => $schema->string()->max(255),
            ], 'Structured differentiator claims; distinguish competitor claims from observed or verified facts.'),
            'channels' => self::entryList($schema, [
                'channel_type' => $schema->string()->enum(['sales', 'distribution', 'acquisition', 'social', 'other'])->required(),
                'name' => $schema->string()->min(1)->max(255)->required(),
                'details' => $schema->string()->max(2000),
            ], 'Structured sales, distribution, acquisition, or social channel entries.'),
            'reputation' => self::entryList($schema, [
                'platform' => $schema->string()->min(1)->max(255)->required(),
                'rating' => $schema->number()->min(0),
                'rating_scale' => $schema->string()->max(100),
                'review_count' => $schema->integer()->min(0),
                'sentiment' => $schema->string()->enum(['positive', 'negative', 'mixed', 'unknown'])->required(),
                'summary' => $schema->string()->max(2000),
                'observed_at' => $schema->string()->max(10),
            ], 'Dated reputation observations with an explicit rating scale when a rating is provided.'),
            'digital_presence' => self::entryList($schema, [
                'channel' => $schema->string()->min(1)->max(255)->required(),
                'url' => $schema->string()->max(2048),
                'observation' => $schema->string()->min(1)->max(2000)->required(),
                'observed_at' => $schema->string()->max(10),
            ], 'Dated digital-presence observations.'),
            'source_references' => self::entryList($schema, [
                'knowledge_reference_id' => $schema->integer()->min(1)->required(),
                'field' => $schema->string()->enum(['name', 'website', 'positioning', 'strengths', 'weaknesses', 'products', 'target_segments', 'pricing', 'markets', 'differentiators', 'channels', 'reputation', 'digital_presence']),
                'claim' => $schema->string()->max(1000),
                'observed_at' => $schema->string()->max(10),
            ], 'Evidence references must resolve to Knowledge records owned by the same Enterprise.'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    /** @param array<string, mixed> $fields */
    private static function entryList(JsonSchema $schema, array $fields, string $description): mixed
    {
        return $schema->array()
            ->items($schema->object($fields)->withoutAdditionalProperties())
            ->max(50)
            ->description($description);
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.competitor.create_and_report', function () use ($request, $authorization, $registry) {
            $result = $this->performCreation($request, $authorization, $registry);

            if (! is_array($result)
                || ! ($result['competitor'] ?? null) instanceof Competitor
                || ! ($result['report'] ?? null) instanceof Report) {
                throw new \LogicException('The create-competitor capability must return both a Competitor and a Report.');
            }

            return Response::structured([
                'success' => true,
                'result' => [
                    'competitor' => $this->serializeCompetitor($result['competitor']),
                    'report' => $this->serializeReport($result['report']),
                ],
            ]);
        });
    }

    protected function performCreation(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): mixed
    {
        $validated = $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'positioning' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'strengths' => ['sometimes', 'array', 'list', 'max:50'],
            'strengths.*' => ['required', 'string', 'min:1', 'max:500'],
            'weaknesses' => ['sometimes', 'array', 'list', 'max:50'],
            'weaknesses.*' => ['required', 'string', 'min:1', 'max:500'],
            ...array_fill_keys(Competitor::PROFILE_FIELDS, ['sometimes', 'array', 'list', 'max:50']),
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
            ['enterprise_id' => $enterprise->getKey()],
            ['createForEnterprise', [Competitor::class, $enterprise]],
        );

        return $this->executeCapability($registry, $actor, ['enterprise' => $enterprise, ...$validated]);
    }

    /** @return array<string, mixed> */
    protected function serializeCompetitor(Competitor $competitor): array
    {
        return [
            'id' => $competitor->getKey(),
            'enterprise_id' => $competitor->enterprise_id,
            'version' => $competitor->version,
            'name' => $competitor->name,
            'website' => $competitor->website,
            'positioning' => $competitor->positioning,
            'strengths' => $competitor->strengths ?? [],
            'weaknesses' => $competitor->weaknesses ?? [],
            ...collect(Competitor::PROFILE_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $competitor->{$field} ?? []])->all(),
            'status' => $competitor->status,
            'effective_from' => self::timestamp($competitor->getRawOriginal('effective_from')),
            'effective_to' => self::timestamp($competitor->getRawOriginal('effective_to')),
            'supersedes_id' => $competitor->supersedes_id,
            'is_current' => $competitor->is_current,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeReport(Report $report): array
    {
        return [
            'id' => $report->getKey(),
            'enterprise_id' => $report->enterprise_id,
            'report_type' => $report->report_type,
            'status' => $report->status,
            'generated_at' => $report->getAttribute('generated_at') instanceof \DateTimeInterface
                ? $report->getAttribute('generated_at')->format(DATE_ATOM)
                : null,
            'methodology_version' => $report->methodology_version,
            'source_fingerprint' => $report->snapshot?->source_fingerprint,
            'report' => $report->result_payload,
        ];
    }

    private static function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
