<?php

use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;
use LogicException;

beforeEach(function (): void {
    $this->seed([
        Database\Seeders\AgentDescriptorSeeder::class,
        Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

function assetPlanningFixture(): array
{
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->getKey(), 'organization_id' => $organization->getKey()]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->getKey(), 'marketing_strategy_id' => $strategy->getKey()]);
    $item = ContentItem::factory()->forCampaign($campaign)->create();
    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey()]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey(), 'actor_name' => $actor->name]);
    $script = Script::factory()->create([
        'content_item_id' => $item->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'asset_requirements' => [[
            'type' => 'image',
            'purpose' => 'hero visual',
            'channel' => 'social',
            'platform' => 'instagram',
            'format' => 'feed',
            'dimensions' => ['width' => 1080, 'height' => 1350, 'aspect_ratio' => '4:5'],
            'duration_seconds' => null,
            'creative_brief' => 'Editorial hero image for the campaign launch.',
        ]],
    ]);

    return compact('actor', 'enterprise', 'item', 'assignment', 'execution', 'script');
}

it('keeps a structured asset requirement contract on the script', function (): void {
    $fixture = assetPlanningFixture();
    $script = $fixture['script']->refresh();

    expect($script->asset_requirements)->toHaveCount(1)
        ->and($script->asset_requirements[0]['type'])->toBe('image')
        ->and($script->asset_requirements[0]['dimensions']['width'])->toBe(1080);
});

it('creates required assets as pending planned work without generating media', function (): void {
    $fixture = assetPlanningFixture();
    extract($fixture);

    $asset = app(App\Operations\CreatePlannedAsset::class)->execute($actor, [
        'script' => $script,
        'script_id' => $script->getKey(),
        'name' => 'Launch hero',
        'type' => 'image',
        'purpose' => 'hero visual',
        'channel' => 'social',
        'platform' => 'instagram',
        'format' => 'feed',
        'width' => 1080,
        'height' => 1350,
        'aspect_ratio' => '4:5',
        'duration_seconds' => null,
        'creative_brief' => 'Editorial hero image for the campaign launch.',
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
    ]);

    expect($asset->status)->toBe(App\Models\Asset::STATUS_PENDING)
        ->and($asset->script_id)->toBe($script->getKey())
        ->and($asset->content_item_id)->toBe($item->getKey())
        ->and($asset->dimensions)->toBe(['width' => 1080, 'height' => 1350, 'aspect_ratio' => '4:5'])
        ->and(App\Models\AssetVersion::query()->where('asset_id', $asset->getKey())->exists())->toBeFalse()
        ->and(App\Models\GenerationRequest::query()->where('asset_id', $asset->getKey())->exists())->toBeFalse()
        ->and(App\Models\RenderRequest::query()->where('asset_id', $asset->getKey())->exists())->toBeFalse();
});

it('preserves pending lifecycle while active and archived remain valid', function (): void {
    $fixture = assetPlanningFixture();
    $pending = App\Models\Asset::factory()->create([
        'enterprise_id' => $fixture['enterprise']->getKey(),
        'content_item_id' => $fixture['item']->getKey(),
        'script_id' => $fixture['script']->getKey(),
        'agent_assignment_id' => $fixture['assignment']->getKey(),
        'agent_execution_id' => $fixture['execution']->getKey(),
        'status' => App\Models\Asset::STATUS_PENDING,
    ]);

    expect(fn () => $pending->update(['status' => App\Models\Asset::STATUS_ACTIVE]))
        ->toThrow(LogicException::class);

    $active = App\Models\Asset::factory()->create(['enterprise_id' => $fixture['enterprise']->getKey(), 'status' => App\Models\Asset::STATUS_ACTIVE]);
    $archived = App\Models\Asset::factory()->create(['enterprise_id' => $fixture['enterprise']->getKey(), 'status' => App\Models\Asset::STATUS_ARCHIVED]);

    expect($active->status)->toBe('active')->and($archived->status)->toBe('archived');
});

it('fails closed when planned asset provenance crosses enterprise boundaries', function (): void {
    $fixture = assetPlanningFixture();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $fixture['enterprise']->organization_id]);
    $foreignStrategy = MarketingStrategy::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);
    $foreignCampaign = Campaign::factory()->create(['enterprise_id' => $foreignEnterprise->getKey(), 'marketing_strategy_id' => $foreignStrategy->getKey()]);
    $foreignItem = ContentItem::factory()->forCampaign($foreignCampaign)->create();
    $foreignDescriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $foreignAssignment = AgentAssignment::factory()->forEnterprise($foreignEnterprise)->create(['agent_descriptor_id' => $foreignDescriptor->getKey()]);
    $foreignExecution = AgentExecution::factory()->forAssignment($foreignAssignment)->create(['actor_id' => $fixture['actor']->getKey(), 'actor_name' => $fixture['actor']->name]);
    $foreignScript = Script::factory()->create([
        'content_item_id' => $foreignItem->getKey(),
        'agent_assignment_id' => $foreignAssignment->getKey(),
        'agent_execution_id' => $foreignExecution->getKey(),
    ]);

    expect(fn () => app(App\Operations\CreatePlannedAsset::class)->execute($fixture['actor'], [
        'script' => $foreignScript,
        'script_id' => $foreignScript->getKey(),
        'name' => 'Forbidden', 'type' => 'image', 'purpose' => 'x', 'channel' => 'social', 'platform' => 'instagram', 'format' => 'feed', 'creative_brief' => 'x',
        'agent_assignment_id' => $fixture['assignment']->getKey(), 'agent_execution_id' => $fixture['execution']->getKey(),
    ]))->toThrow(LogicException::class);
});

it('executes planning through the Agent and Copywriting Expert without invoking media', function (): void {
    $fixture = assetPlanningFixture();
    extract($fixture);

    $provider = new FakeModelProvider(function ($request) use ($script): ModelResult {
        return new ModelResult(
            text: 'Plan the required campaign asset.',
            structured: [
                'answer' => 'Plan the required campaign asset.',
                'decision_title' => 'Asset planning',
                'decision_summary' => 'Persist the required creative as pending planned work.',
                'decision_rationale' => 'The Copywriting Expert may plan assets but does not generate or publish media.',
                'capability_requests' => [json_encode([
                    'capability' => 'marketing.asset.create',
                    'expert_slug' => 'copywriting',
                    'target_context' => ['script_id' => $script->getKey()],
                    'input_payload' => [
                        'script_id' => $script->getKey(), 'name' => 'Agent planned hero', 'type' => 'image', 'purpose' => 'hero visual',
                        'channel' => 'social', 'platform' => 'instagram', 'format' => 'feed', 'width' => 1080, 'height' => 1350,
                        'aspect_ratio' => '4:5', 'creative_brief' => 'Agent-planned hero asset.',
                    ],
                ], JSON_THROW_ON_ERROR)],
            ],
            provider: 'fake', model: 'test', invocationId: 'asset-plan-e2e', correlationId: $request->correlationId,
        );
    });

    $service = new AgentExecutionService($provider, app(McpContextAssembler::class), app(AgentCapabilityAuthorizer::class));
    $result = $service->execute(new AgentExecutionRequest(
        actor: $actor, assignment: $assignment, prompt: 'Plan assets for this script.', expertSlugs: ['copywriting'], correlationId: 'asset-plan-e2e',
    ));

    expect($result->succeeded())->toBeTrue()->and($result->capabilityRequests[0]->capability)->toBe('marketing.asset.create');

    $request = $result->capabilityRequests[0];
    $asset = app(CapabilityRegistry::class)->operation($request->capability)->execute($actor, [
        ...$request->inputPayload,
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $result->execution->getKey(),
    ]);

    expect($asset->status)->toBe(App\Models\Asset::STATUS_PENDING)->and($asset->script_id)->toBe($script->getKey());
});
it('persists structured asset requirements through governed script creation', function (): void {
    $fixture = assetPlanningFixture();
    extract($fixture);

    $script = app(App\Operations\CreateScript::class)->execute($actor, [
        'content_item' => $item,
        'content_item_id' => $item->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'title' => 'Script with asset requirements',
        'body' => 'Draft copy.',
        'asset_requirements' => [[
            'type' => 'video',
            'purpose' => 'launch reel',
            'channel' => 'social',
            'platform' => 'instagram',
            'format' => 'reel',
            'dimensions' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
            'duration_seconds' => 30,
            'creative_brief' => 'Short vertical launch video.',
        ]],
    ]);

    expect($script->asset_requirements)->toHaveCount(1)
        ->and($script->asset_requirements[0]['format'])->toBe('reel')
        ->and($script->asset_requirements[0]['duration_seconds'])->toBe(30);
});
