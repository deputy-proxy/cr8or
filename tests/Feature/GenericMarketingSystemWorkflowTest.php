<?php

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

    $callerInput = [
        'stages' => [
            'strategy' => ['name' => 'Generic Growth Strategy', 'description' => 'Caller-supplied strategy.'],
            'audience' => ['name' => 'Primary Growth Audience', 'description' => 'Caller-supplied audience.'],
            'campaign' => ['name' => 'Primary Growth Campaign', 'description' => 'Caller-supplied campaign.'],
            'content_series' => ['name' => 'Primary Growth Series', 'description' => 'Caller-supplied series.'],
            'content' => ['title' => 'Primary Growth Content', 'body' => 'Caller-supplied content body.'],
            'script' => ['title' => 'Primary Growth Script', 'body' => 'Caller-supplied production script.'],
            'asset' => [
                'name' => 'Primary Growth Asset',
                'type' => 'video',
                'purpose' => 'Introduce the campaign message.',
                'channel' => 'social',
                'platform' => 'instagram',
                'format' => 'reel',
                'creative_brief' => 'A concise visual treatment for the primary growth campaign.',
            ],
        ],
    ];

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $workflow->publishedVersion,
        [],
        'generic-marketing-system-e2e-'.$enterprise->getKey(),
        'generic-marketing-system-e2e-correlation',
        false,
        $enterprise,
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_WAITING_FOR_INPUT)
        ->and($execution->current_stage_key)->toBe('strategy');

    $execution = app(WorkflowExecutionService::class)->continue(
        $actor,
        $execution,
        $execution->continuation_token,
        false,
        $callerInput,
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
        ->and($execution->context['stage_input_resolutions']['strategy']['sources'])->toMatchArray([
            'name' => 'explicit',
            'description' => 'explicit',
        ])
        ->and($execution->context['stage_input_resolutions']['campaign']['sources'])->toMatchArray([
            'marketing_strategy_id' => 'mapped',
            'name' => 'explicit',
            'description' => 'explicit',
        ])
        ->and($execution->context['stage_input_resolutions']['content']['sources'])->toMatchArray([
            'campaign_id' => 'mapped',
            'content_series_id' => 'mapped',
            'audience_id' => 'mapped',
            'title' => 'explicit',
            'body' => 'explicit',
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