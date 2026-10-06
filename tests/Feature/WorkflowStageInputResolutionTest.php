<?php

use App\AI\Contracts\ModelProvider;
use App\AI\Providers\FakeModelProvider;
use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowStageInputResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function inputResolutionActor(Enterprise $enterprise): User
{
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    return $user;
}

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'marketing',
    ], [
        'runtime_class' => App\Experts\MarketingExpert::class,
        'enabled' => true,
    ]);
});

it('resolves explicit, mapped, default and generated inputs without overwriting deterministic values', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = inputResolutionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);

    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
        'execution_policy' => [
            'mode' => 'interactive',
            'requires_model_provider' => true,
        ],
        'stage_definitions' => [],
    ]);

    $stage = WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => 'content_series',
        'sequence' => 1,
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['marketing.content-series.create'],
        'capability_input_contract' => [
            'campaign_id' => 'integer|required',
            'name' => 'string|required',
            'description' => 'string|nullable',
        ],
        'input_contract' => [
            'required' => ['campaign_id', 'name', 'description'],
            'generated' => ['name', 'description'],
            'mappings' => ['campaign_id' => 'campaign.id'],
        ],
        'output_contract' => ['required' => ['id']],
    ]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => 1,
        'enterprise_id' => $enterprise,
        'actor_id' => $actor,
        'status' => WorkflowExecution::STATUS_RUNNING,
        'input' => [
            'stages' => [
                'content_series' => [
                    'name' => 'Explicit name',
                ],
            ],
        ],
        'context' => [
            'workflow' => [],
            'campaign' => ['id' => $campaign->getKey()],
        ],
    ]);

    app()->instance(ModelProvider::class, FakeModelProvider::returning(
        structured: [
            'name' => 'Generated name',
            'description' => 'Generated description',
        ],
    ));

    $resolved = app(WorkflowStageInputResolver::class)->resolve(
        $execution,
        $version,
        $stage,
        $execution->input,
    );

    expect($resolved->inputs['enterprise_id'])->toBe($enterprise->getKey())
        ->and($resolved->inputs['name'])->toBe('Explicit name')
        ->and($resolved->inputs['description'])->toBe('Generated description')
        ->and($resolved->inputs['campaign_id'])->toBe($campaign->getKey())
        ->and($resolved->sources['name'])->toBe('explicit')
        ->and($resolved->sources['description'])->toBe('generated')
        ->and($resolved->sources['campaign_id'])->toBe('mapped');
});

it('fails generated input resolution when the workflow policy is provider-free', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = inputResolutionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
        'execution_policy' => [
            'mode' => 'interactive',
            'requires_model_provider' => false,
        ],
    ]);
    $stage = WorkflowStage::factory()->make([
        'workflow_id' => $workflow,
        'key' => 'content_series',
        'input_contract' => [
            'required' => ['name'],
            'generated' => ['name'],
        ],
        'capability_input_contract' => ['name' => 'string|required'],
    ]);
    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => 1,
        'enterprise_id' => $enterprise,
        'actor_id' => $actor,
        'context' => [],
    ]);

    expect(fn () => app(WorkflowStageInputResolver::class)->resolve(
        $execution,
        $version,
        $stage,
        [],
    ))->toThrow(\App\Exceptions\WorkflowDefinitionException::class, 'does not permit a ModelProvider');
});

it('validates generated values against the capability input contract', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = inputResolutionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
        'execution_policy' => ['requires_model_provider' => true],
    ]);
    $stage = WorkflowStage::factory()->make([
        'workflow_id' => $workflow,
        'key' => 'generated',
        'input_contract' => [
            'required' => ['name'],
            'generated' => ['name'],
        ],
        'capability_input_contract' => ['name' => 'integer|required'],
    ]);
    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => 1,
        'enterprise_id' => $enterprise,
        'actor_id' => $actor,
        'context' => [],
    ]);

    app()->instance(ModelProvider::class, FakeModelProvider::returning(
        structured: ['name' => 'not-an-integer'],
    ));

    expect(fn () => app(WorkflowStageInputResolver::class)->resolve(
        $execution,
        $version,
        $stage,
        [],
    ))->toThrow(ValidationException::class, 'does not match its Capability contract');
});

it('persists input resolution provenance and executes generated content-series inputs through the Capability boundary', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = inputResolutionActor($enterprise);
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise, 'marketing_strategy_id' => $strategy]);

    $stageDefinition = [
        'key' => 'content_series',
        'name' => 'Create Content Series',
        'instruction' => 'Create a clear content series name and description from the campaign context.',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['marketing.content-series.create'],
        'capability_input_contract' => [
            'campaign_id' => 'integer|required',
            'name' => 'string|required',
            'description' => 'string|nullable',
            'agent_assignment_id' => 'integer|nullable',
            'agent_execution_id' => 'integer|nullable',
            'approval_request_id' => 'integer|nullable',
        ],
        'input_contract' => [
            'required' => ['campaign_id', 'name', 'description'],
            'generated' => ['name', 'description'],
            'mappings' => ['campaign_id' => 'campaign.id'],
        ],
        'output_contract' => ['required' => ['id']],
        'repeatable' => false,
        'completion_criteria' => ['requires_termination_completed' => true],
    ];

    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow,
        'enterprise_id' => $enterprise,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'version' => 1,
        'execution_policy' => [
            'mode' => 'interactive',
            'requires_model_provider' => true,
        ],
        'stage_definitions' => [$stageDefinition],
    ]);

    WorkflowStage::factory()->create($stageDefinition + ['workflow_id' => $workflow]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $version,
        'workflow_version' => 1,
        'enterprise_id' => $enterprise,
        'actor_id' => $actor,
        'status' => WorkflowExecution::STATUS_PENDING,
        'input' => [
            'stages' => [],
        ],
        'context' => [
            'campaign' => ['id' => $campaign->getKey()],
        ],
    ]);

    app()->instance(ModelProvider::class, FakeModelProvider::returning(
        structured: [
            'name' => 'Generated creator series',
            'description' => 'A series generated from the persisted stage instruction.',
        ],
    ));

    $execution = app(WorkflowExecutionService::class)->continue($actor, $execution);

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->outputs['content_series']['id'])->toBeInt()
        ->and($execution->context['stage_input_resolutions']['content_series']['sources'])->toMatchArray([
            'campaign_id' => 'mapped',
            'name' => 'generated',
            'description' => 'generated',
        ]);
});
