<?php

use App\AI\Contracts\ExecutionErrorType;
use App\AI\Contracts\FailureCode;
use App\Exceptions\WorkflowDefinitionException;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Services\FailureTranslator;
use App\Services\WorkflowVersionService;
use Illuminate\Auth\Access\AuthorizationException;

function definitionActor(Enterprise $enterprise): User
{
    $actor = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'marketing'],
        ['runtime_class' => \App\Experts\MarketingExpert::class, 'enabled' => true],
    );

    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'business-analysis'],
        ['runtime_class' => \App\Experts\BusinessAnalysisExpert::class, 'enabled' => true],
    );

    return $actor;
}

function definitionStage(Workflow $workflow, array $overrides = []): WorkflowStage
{
    return WorkflowStage::factory()->create(array_merge([
        'workflow_id' => $workflow->getKey(),
        'key' => 'stage-1',
        'name' => 'Stage 1',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'input_contract' => ['required' => ['request']],
        'output_contract' => ['required' => ['analysis']],
    ], $overrides));
}

it('rejects a capability that is not exposed by the declared Expert before publication', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = definitionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);

    definitionStage($workflow, [
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['marketing.channel.create'],
    ]);

    try {
        app(WorkflowVersionService::class)->publish($workflow, $actor, 'invalid-expert-capability');
        expect(false)->toBeTrue();
    } catch (WorkflowDefinitionException $exception) {
        expect(collect($exception->errors)->pluck('code'))->toContain('expert.capability.mismatch');
    }

    expect($workflow->refresh()->published_version_id)->toBeNull();
});

it('rejects unknown and disabled Experts and unknown Capabilities before publication', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = definitionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);

    definitionStage($workflow, [
        'expert_slugs' => ['missing-expert'],
        'capability_slugs' => ['missing.capability'],
    ]);

    expect(fn () => app(WorkflowVersionService::class)->publish($workflow, $actor, 'unknown-contract'))
        ->toThrow(WorkflowDefinitionException::class);

    ExpertDescriptor::query()->where('slug', 'business-analysis')->update(['enabled' => false]);

    $workflow->stages()->update([
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
    ]);

    try {
        app(WorkflowVersionService::class)->publish($workflow, $actor, 'disabled-expert');
        expect(false)->toBeTrue();
    } catch (WorkflowDefinitionException $exception) {
        expect(collect($exception->errors)->pluck('code'))->toContain('expert.disabled');
    }
});

it('rejects invalid stage dependencies and mappings before publication', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = definitionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);

    definitionStage($workflow, [
        'input_contract' => [
            'required' => ['request'],
            'mappings' => ['request' => 'stages.missing'],
        ],
        'dependencies' => [],
    ]);

    try {
        app(WorkflowVersionService::class)->publish($workflow, $actor, 'invalid-mapping');
        expect(false)->toBeTrue();
    } catch (WorkflowDefinitionException $exception) {
        expect(collect($exception->errors)->pluck('code'))->toContain('mapping.stage.missing');
    }
});

it('publishes a valid persisted workflow and leaves the published version immutable', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = definitionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    definitionStage($workflow);

    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'valid-definition');

    expect($version->status)->toBe('published')
        ->and($workflow->refresh()->published_version_id)->toBe($version->getKey());

    $version->name = 'tampered';

    expect(fn () => $version->save())
        ->toThrow(LogicException::class, 'immutable');
});

it('distinguishes persisted workflow definition failures from actor authorization failures', function (): void {
    $failure = app(FailureTranslator::class)->translate(
        new WorkflowDefinitionException(
            'The persisted Workflow definition is invalid.',
            [['code' => 'expert.capability.mismatch', 'message' => 'invalid pairing']],
        ),
        correlationId: 'workflow-definition-contract',
    );

    expect($failure->type)->toBe(ExecutionErrorType::Configuration)
        ->and($failure->code)->toBe(FailureCode::CONFIGURATION_INVALID)
        ->and($failure->details['errors'][0]['code'])->toBe('expert.capability.mismatch');

    $authorization = app(FailureTranslator::class)->translate(
        new AuthorizationException('The actor is not authorized.'),
        correlationId: 'workflow-actor-authorization',
    );

    expect($authorization->type)->toBe(ExecutionErrorType::Authorization)
        ->and($authorization->code)->toBe(FailureCode::AUTHORIZATION_DENIED);
});

it('allows deterministic graph verification without Agent assignment or execution identifiers', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = definitionActor($enterprise);
    $strategy = \App\Models\MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $audience = \App\Models\Audience::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = \App\Models\Campaign::factory()->create(['enterprise_id' => $enterprise->getKey(), 'marketing_strategy_id' => $strategy->getKey()]);
    $series = \App\Models\ContentSeries::factory()->create(['campaign_id' => $campaign->getKey()]);
    $item = \App\Models\ContentItem::factory()->forSeries($series)->create(['audience_id' => $audience->getKey()]);
    $agentDescriptor = \App\Models\AgentDescriptor::factory()->forRuntimeClass(\App\Agents\MarketingAgent::class)->create(['slug' => 'marketing']);
    $assignment = \App\Models\AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $agentDescriptor->getKey()]);
    $agentExecution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->forEnterprise($enterprise)->create();
    $script = \App\Models\Script::factory()->create(['content_item_id' => $item->getKey(), 'agent_assignment_id' => $assignment->getKey(), 'agent_execution_id' => $agentExecution->getKey()]);
    $asset = \App\Models\Asset::factory()->create(['enterprise_id' => $enterprise->getKey(), 'content_item_id' => $item->getKey(), 'script_id' => $script->getKey(), 'agent_assignment_id' => $assignment->getKey(), 'agent_execution_id' => $agentExecution->getKey(), 'status' => \App\Models\Asset::STATUS_PENDING]);

    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    definitionStage($workflow, [
        'key' => 'verification',
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['marketing.graph.verify'],
        'input_contract' => [
            'required' => [
                'marketing_strategy_id', 'audience_ids', 'campaign_ids', 'content_series_ids',
                'content_item_ids', 'script_ids', 'asset_ids',
            ],
        ],
        'output_contract' => ['required' => ['verification_passed']],
    ]);

    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'deterministic-graph-verify');

    $execution = app(\App\Services\WorkflowExecutionService::class)->start(
        $actor,
        $version,
        [
            'marketing_strategy_id' => $strategy->getKey(),
            'audience_ids' => [$audience->getKey()],
            'campaign_ids' => [$campaign->getKey()],
            'content_series_ids' => [$series->getKey()],
            'content_item_ids' => [$item->getKey()],
            'script_ids' => [$script->getKey()],
            'asset_ids' => [$asset->getKey()],
        ],
        'deterministic-graph-verify-execution',
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->outputs['verification']['verification_passed'])->toBeTrue();
});