<?php

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Enums\AgentExecutionMode;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateAgentExecutionTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentRuntimePolicy;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Goal;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\Kpi;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Script;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

function fullMarketingProvider(bool &$pauseAtCampaign = false): FakeModelProvider
{
    return new FakeModelProvider(function ($request) use (&$pauseAtCampaign): ModelResult {
        $stage = $request->context['workflow_stage']['key'] ?? null;
        $previous = $request->context['previous_result']['capability_results'] ?? [];
        $resultFor = static function (array $results, string $capability): array {
            foreach (array_reverse($results) as $result) {
                if (($result['capability'] ?? null) === $capability && is_array($result['result'] ?? null)) {
                    return $result['result'];
                }
            }

            return [];
        };

        if ($stage === 'campaigns' && $pauseAtCampaign) {
            $pauseAtCampaign = false;

            return new ModelResult(
                text: 'Waiting for continuation.',
                structured: ['answer' => 'Waiting for continuation.', 'termination' => 'waiting_for_input', 'capability_requests' => []],
                provider: 'fake', model: 'mcp-e2e', invocationId: 'pause-'.$request->context['step'] ?? 1, correlationId: $request->correlationId,
            );
        }

        $audience = $resultFor($previous, 'marketing.audience.create');
        $strategy = $resultFor($previous, 'marketing.strategy.create');
        $campaign = $resultFor($previous, 'marketing.campaign.create');
        $series = $resultFor($previous, 'marketing.content-series.create');
        $item = $resultFor($previous, 'marketing.content.create');
        $script = $resultFor($previous, 'marketing.script.create');

        $payload = match ($stage) {
            'enterprise-analysis' => ['capability' => 'marketing.plan', 'expert_slug' => 'marketing'],
            'audiences' => ['capability' => 'marketing.audience.create', 'expert_slug' => 'marketing', 'input_payload' => ['name' => 'Design-conscious founders', 'description' => 'Founders evaluating brand and product design.']],
            'strategy' => ['capability' => 'marketing.strategy.create', 'expert_slug' => 'marketing', 'input_payload' => ['name' => 'Blckdsgn Growth Strategy', 'description' => 'A governed strategy for design-led growth.']],
            'campaigns' => ['capability' => 'marketing.campaign.create', 'expert_slug' => 'marketing', 'input_payload' => ['marketing_strategy_id' => $strategy['id'], 'name' => 'Design-led growth campaign']],
            'content-series' => ['capability' => 'marketing.content-series.create', 'expert_slug' => 'marketing', 'input_payload' => ['campaign_id' => $campaign['id'], 'name' => 'Design principles series']],
            'content-items' => ['capability' => 'marketing.content.create', 'expert_slug' => 'copywriting', 'input_payload' => ['campaign_id' => $campaign['id'], 'content_series_id' => $series['id'], 'audience_id' => $audience['id'], 'title' => 'Why design changes growth', 'body' => 'Draft educational content.']],
            'scripts' => ['capability' => 'marketing.script.create', 'expert_slug' => 'copywriting', 'input_payload' => ['content_item_id' => $item['id'], 'title' => 'Design growth reel', 'body' => 'Script draft.', 'asset_requirements' => [['type' => 'video', 'purpose' => 'social launch reel', 'channel' => 'social', 'platform' => 'instagram', 'format' => 'reel', 'dimensions' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'], 'duration_seconds' => 30, 'creative_brief' => 'Vertical educational launch reel.']]]],
            'assets' => ['capability' => 'marketing.asset.create', 'expert_slug' => 'copywriting', 'input_payload' => ['script_id' => $script['id'], 'name' => 'Design growth reel', 'type' => 'video', 'purpose' => 'social launch reel', 'channel' => 'social', 'platform' => 'instagram', 'format' => 'reel', 'width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16', 'duration_seconds' => 30, 'creative_brief' => 'Vertical educational launch reel.']],
            'verification' => ['capability' => 'marketing.graph.verify', 'expert_slug' => 'marketing', 'input_payload' => [
                'marketing_strategy_id' => $strategy['id'],
                'audience_ids' => [$audience['id']],
                'campaign_ids' => [$campaign['id']],
                'content_series_ids' => [$series['id']],
                'content_item_ids' => [$item['id']],
                'script_ids' => [$script['id']],
                'asset_ids' => array_values(Asset::query()->where('script_id', $script['id'])->pluck('id')->all()),
            ]],
            default => throw new RuntimeException("Unexpected workflow stage [{$stage}]."),
        };

        return new ModelResult(
            text: "Completed {$stage}.",
            structured: ['answer' => "Completed {$stage}.", 'decision_title' => "Marketing workflow: {$stage}", 'decision_summary' => "Completed governed stage {$stage}.", 'decision_rationale' => 'The stage used only its declared Expert and Capability route.', 'capability_requests' => [json_encode($payload, JSON_THROW_ON_ERROR)]],
            provider: 'fake', model: 'mcp-e2e', invocationId: 'stage-'.$stage.'-'.$request->context['step'] ?? 1, correlationId: $request->correlationId,
        );
    });
}

function fullMarketingFixture(): array
{
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create(['name' => 'blckdsgn.com', 'slug' => 'blckdsgn-com']);
    Membership::factory()->owner()->create(['user_id' => $user->getKey(), 'organization_id' => $enterprise->organization_id]);
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->getKey(), 'industry' => 'design technology', 'business_model' => 'services', 'target_market' => 'Design-led companies']);
    Product::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Brand Systems']);
    Goal::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Increase qualified demand']);
    Kpi::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Qualified leads']);
    $knowledgeContext = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Brand knowledge']);
    $source = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $document = KnowledgeDocument::factory()->create(['enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey()]);
    KnowledgeItem::factory()->create(['enterprise_id' => $enterprise->getKey(), 'knowledge_source_id' => $source->getKey(), 'knowledge_document_id' => $document->getKey(), 'knowledge_context_id' => $knowledgeContext->getKey(), 'title' => 'Brand positioning evidence']);
    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey(), 'status' => AgentAssignment::STATUS_READY]);
    $workflow = persistedMarketingAgentWorkflow($enterprise, $user);
    AgentRuntimePolicy::query()->create(['environment' => app()->environment(), 'organization_id' => $enterprise->organization_id, 'enterprise_id' => $enterprise->getKey(), 'agent_descriptor_id' => $descriptor->getKey(), 'enabled' => true, 'max_steps' => 12, 'max_retries' => 3, 'timeout_seconds' => 120, 'max_context_bytes' => 120000, 'retrieved_knowledge_limit' => 5, 'memory_limit' => 20]);

    return [$user, $enterprise, $assignment, $workflow];
}

it('completes the full autonomous marketing graph starting from one MCP execution', function (): void {
    config(['queue.default' => 'sync']);
    $provider = fullMarketingProvider();
    app()->instance(ModelProvider::class, $provider);
    [$user, $enterprise, $assignment, $workflow] = fullMarketingFixture();

    $response = Cr8orServer::actingAs($user, 'api')->tool(CreateAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'workflow_id' => $workflow->getKey(),
        'prompt' => 'Generate and implement a full marketing strategy for the enterprise blckdsgn.com.',
        'mode' => AgentExecutionMode::AUTONOMOUS->value,
        'idempotency_key' => 'full-marketing-mcp-e2e-1',
    ]);
    $response->assertOk()->assertSee('full-marketing-mcp-e2e-1');

    $execution = AgentExecution::query()->where('idempotency_key', 'full-marketing-mcp-e2e-1')->firstOrFail();
    $workflow = Workflow::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();
    $firstStep = $execution->steps()->orderBy('sequence')->firstOrFail();
    $context = is_array($firstStep->input_context) ? $firstStep->input_context : [];

    expect($execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($workflow->status)->toBe(Workflow::STATUS_SUCCEEDED)
        ->and($workflow->stages()->count())->toBe(9)
        ->and($context['enterprise']['enterprise']['slug'] ?? null)->toBe('blckdsgn-com')
        ->and($context['enterprise'])->not->toBeEmpty()
        ->and($context['knowledge'])->not->toBeEmpty()
        ->and(MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(DB::table('audiences')->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Campaign::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(ContentSeries::query()->whereHas('campaign', fn ($q) => $q->where('enterprise_id', $enterprise->getKey()))->count())->toBe(1)
        ->and(ContentItem::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Script::query()->whereHas('contentItem', fn ($q) => $q->where('enterprise_id', $enterprise->getKey()))->count())->toBe(1)
        ->and(Asset::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Asset::query()->where('enterprise_id', $enterprise->getKey())->where('status', Asset::STATUS_PENDING)->count())->toBe(1)
        ->and(DB::table('generation_requests')->count())->toBe(0)
        ->and(DB::table('render_requests')->count())->toBe(0)
        ->and(DB::table('publications')->count())->toBe(0);

    $strategy = MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();
    $campaign = Campaign::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();
    $series = ContentSeries::query()->firstOrFail();
    $item = ContentItem::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();
    $script = Script::query()->firstOrFail();
    $asset = Asset::query()->firstOrFail();

    expect($campaign->marketing_strategy_id)->toBe($strategy->getKey())
        ->and($series->campaign_id)->toBe($campaign->getKey())
        ->and($item->content_series_id)->toBe($series->getKey())
        ->and($script->content_item_id)->toBe($item->getKey())
        ->and($asset->script_id)->toBe($script->getKey())
        ->and($asset->status)->toBe(Asset::STATUS_PENDING);
});

it('replaying the same MCP idempotency key does not duplicate the completed graph', function (): void {
    config(['queue.default' => 'sync']);
    app()->instance(ModelProvider::class, fullMarketingProvider());
    [$user, $enterprise, $assignment, $workflow] = fullMarketingFixture();
    $server = Cr8orServer::actingAs($user, 'api');
    $input = [
        'enterprise_id' => $enterprise->getKey(), 'agent_assignment_id' => $assignment->getKey(),
        'workflow_id' => $workflow->getKey(),
        'prompt' => 'Generate and implement a full marketing strategy for the enterprise blckdsgn.com.',
        'mode' => 'autonomous', 'idempotency_key' => 'full-marketing-idempotent-1',
    ];

    $server->tool(CreateAgentExecutionTool::class, $input)->assertOk();
    $first = AgentExecution::query()->where('idempotency_key', $input['idempotency_key'])->firstOrFail();
    $counts = [
        'strategies' => MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->count(),
        'campaigns' => Campaign::query()->where('enterprise_id', $enterprise->getKey())->count(),
        'series' => ContentSeries::query()->count(), 'items' => ContentItem::query()->count(), 'scripts' => Script::query()->count(), 'assets' => Asset::query()->count(),
    ];

    $server->tool(CreateAgentExecutionTool::class, $input)->assertOk();
    $second = AgentExecution::query()->where('idempotency_key', $input['idempotency_key'])->firstOrFail();

    expect($second->getKey())->toBe($first->getKey())
        ->and(MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe($counts['strategies'])
        ->and(Campaign::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe($counts['campaigns'])
        ->and(ContentSeries::query()->count())->toBe($counts['series'])
        ->and(ContentItem::query()->count())->toBe($counts['items'])
        ->and(Script::query()->count())->toBe($counts['scripts'])
        ->and(Asset::query()->count())->toBe($counts['assets']);
});