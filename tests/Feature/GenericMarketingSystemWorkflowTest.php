<?php

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use App\Services\WorkflowExecutionService;
use Database\Seeders\GenericMarketingWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provisions the generic platform-owned marketing system workflow with a published version', function (): void {
    $actor = User::factory()->create([
        'email' => 'test@example.com',
    ]);
    $organization = \App\Models\Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $organization,
    ]);

    $this->seed(GenericMarketingWorkflowSeeder::class);

    $workflow = Workflow::query()
        ->where('canonical_key', 'marketing.system.create')
        ->where('enterprise_specific', false)
        ->firstOrFail();

    expect($workflow->enterprise_id)->toBeNull()
        ->and($workflow->publishedVersion)->not->toBeNull()
        ->and($workflow->publishedVersion->status)->toBe(WorkflowVersion::STATUS_PUBLISHED)
        ->and($workflow->stages()->pluck('key')->all())->toBe([
            'strategy',
            'audience',
            'campaign',
            'content_series',
            'content',
            'script',
            'asset',
            'verification',
        ])
        ->and($workflow->stages()->where('key', 'campaign')->value('input_contract'))->toMatchArray([
            'mappings' => [
                'marketing_strategy_id' => 'stages.strategy.id',
            ],
        ]);
});

it('executes the generic marketing system workflow end to end for an authorized enterprise', function (): void {
    $actor = User::factory()->create([
        'email' => 'test@example.com',
    ]);
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $enterprise->organization_id,
    ]);

    $this->seed(GenericMarketingWorkflowSeeder::class);

    /** @var Workflow $workflow */
    $workflow = Workflow::query()
        ->where('canonical_key', 'marketing.system.create')
        ->where('enterprise_specific', false)
        ->firstOrFail();

    $this->assertTrue(
        $workflow->publishedVersion instanceof WorkflowVersion,
    );

    $generated = [
        'strategy' => [
            'name' => 'Generic Growth Strategy',
            'description' => 'A generated strategy for the authorized Enterprise.',
        ],
        'audience' => [
            'name' => 'Primary Growth Audience',
            'description' => 'The primary audience defined from Enterprise context.',
        ],
        'campaign' => [
            'name' => 'Primary Growth Campaign',
            'description' => 'The first campaign aligned to the strategy.',
        ],
        'content_series' => [
            'name' => 'Primary Growth Series',
            'description' => 'A reusable series for the campaign.',
        ],
        'content' => [
            'title' => 'Primary Growth Content',
            'body' => 'Generated content body for the primary campaign.',
        ],
        'script' => [
            'title' => 'Primary Growth Script',
            'body' => 'Generated production script for the primary content item.',
        ],
        'asset' => [
            'name' => 'Primary Growth Asset',
            'type' => 'video',
            'purpose' => 'Introduce the campaign message.',
            'channel' => 'social',
            'platform' => 'instagram',
            'format' => 'reel',
            'creative_brief' => 'A concise visual treatment for the primary growth campaign.',
        ],
    ];

    app()->instance(
        ModelProvider::class,
        new FakeModelProvider(
            static function (ModelRequest $request) use ($generated): ModelResult {
                $stageKey = $request->context['stage']['key'] ?? null;

                return new ModelResult(
                    text: 'Generated workflow input.',
                    structured: is_string($stageKey) ? ($generated[$stageKey] ?? []) : [],
                    provider: 'fake',
                    model: 'fake-model',
                    invocationId: 'generic-marketing-e2e-'.$stageKey,
                    correlationId: $request->correlationId,
                );
            },
        ),
    );

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $workflow->publishedVersion,
        [],
        'generic-marketing-system-e2e-'.$enterprise->getKey(),
        'generic-marketing-system-e2e-correlation',
        false,
        $enterprise,
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->outputs)->toHaveKeys([
            'strategy',
            'audience',
            'campaign',
            'content_series',
            'content',
            'script',
            'asset',
            'verification',
        ])
        ->and($execution->outputs['verification']['verification_passed'])->toBeTrue()
        ->and($execution->context['stage_input_resolutions']['campaign']['sources']['marketing_strategy_id'])->toBe('mapped')
        ->and($execution->context['stage_input_resolutions']['content']['sources'])->toMatchArray([
            'campaign_id' => 'mapped',
            'content_series_id' => 'mapped',
            'audience_id' => 'mapped',
            'title' => 'generated',
            'body' => 'generated',
        ])
        ->and($execution->context['stage_input_resolutions']['verification']['sources'])->toMatchArray([
            'marketing_strategy_id' => 'mapped',
            'audience_ids' => 'mapped',
            'campaign_ids' => 'mapped',
            'content_series_ids' => 'mapped',
            'content_item_ids' => 'mapped',
            'script_ids' => 'mapped',
            'asset_ids' => 'mapped',
        ])
        ->and(\App\Models\AgentExecution::query()->count())->toBe(0);
});
