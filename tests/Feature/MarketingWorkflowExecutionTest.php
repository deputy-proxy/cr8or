<?php

use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Enums\AgentExecutionMode;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\AgentRuntimePolicy;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Script;
use App\Models\User;
use App\Models\Workflow;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

it('auto-selects the full marketing workflow and completes the governed graph without generation or publication', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create(['name' => 'blckdsgn.com', 'slug' => 'blckdsgn-com']);
    Membership::factory()->owner()->create(['user_id' => $actor->getKey(), 'organization_id' => $enterprise->organization_id]);
    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey()]);
    $workflow = persistedMarketingAgentWorkflow($enterprise, $actor);
    AgentRuntimePolicy::query()->create(['environment' => app()->environment(), 'organization_id' => $enterprise->organization_id, 'enterprise_id' => $enterprise->getKey(), 'agent_descriptor_id' => $descriptor->getKey(), 'enabled' => true, 'max_steps' => 12, 'max_retries' => 3, 'timeout_seconds' => 120, 'max_context_bytes' => 120000, 'retrieved_knowledge_limit' => 5, 'memory_limit' => 20]);
    $provider = new FakeModelProvider(function ($request): ModelResult {
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
            structured: [
                'answer' => "Completed {$stage}.",
                'decision_title' => "Marketing workflow: {$stage}",
                'decision_summary' => "Completed governed stage {$stage}.",
                'decision_rationale' => 'The stage used only its declared Expert and Capability route.',
                'capability_requests' => [json_encode($payload, JSON_THROW_ON_ERROR)],
                'marketing_plan' => $stage === 'enterprise-analysis' ? 'Authorized enterprise marketing analysis.' : null,
            ],
            provider: 'fake', model: 'workflow-test', invocationId: 'workflow-'.$stage, correlationId: $request->correlationId,
        );
    });

    $result = (new AgentExecutionService($provider, app(McpContextAssembler::class), app(AgentCapabilityAuthorizer::class)))
        ->execute(new AgentExecutionRequest(
            actor: $actor,
            assignment: $assignment,
            workflow: $workflow,
            prompt: 'Generate and implement a full marketing strategy for the enterprise blckdsgn.com.',
            mode: AgentExecutionMode::AUTONOMOUS,
            correlationId: 'full-marketing-workflow-test',
        ));

    $workflow = Workflow::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    expect($result->execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($workflow->status)->toBe(Workflow::STATUS_SUCCEEDED)
        ->and($workflow->stages()->count())->toBe(9)
        ->and(MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Campaign::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(ContentSeries::query()->count())->toBe(1)
        ->and(ContentItem::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Script::query()->count())->toBe(1)
        ->and(Asset::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1)
        ->and(Asset::query()->first()->status)->toBe(Asset::STATUS_PENDING)
        ->and(DB::table('generation_requests')->count())->toBe(0)
        ->and(DB::table('render_requests')->count())->toBe(0)
        ->and(DB::table('publications')->count())->toBe(0);
});

it('does not create a second marketing workflow when an autonomous execution is queued idempotently', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->getKey(), 'organization_id' => $enterprise->organization_id]);
    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey()]);
    $workflow = persistedMarketingAgentWorkflow($enterprise, $actor);
    AgentRuntimePolicy::query()->create(['environment' => app()->environment(), 'organization_id' => $enterprise->organization_id, 'enterprise_id' => $enterprise->getKey(), 'agent_descriptor_id' => $descriptor->getKey(), 'enabled' => true, 'max_steps' => 12, 'max_retries' => 3, 'timeout_seconds' => 120, 'max_context_bytes' => 120000, 'retrieved_knowledge_limit' => 5, 'memory_limit' => 20]);

    $service = app(AgentExecutionService::class);
    $request = fn () => new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        workflow: $workflow,
        prompt: 'Generate and implement a full marketing strategy.',
        correlationId: 'workflow-idempotency',
    );

    $first = $service->queue($request());
    $second = $service->queue($request());

    expect($second->getKey())->toBe($first->getKey())
        ->and(Workflow::query()->where('enterprise_id', $enterprise->getKey())->count())->toBe(1);
    Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAgentExecutionJob::class, 1);
});