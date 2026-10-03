<?php

use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowVersionService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'business-analysis'],
        ['runtime_class' => \App\Experts\BusinessAnalysisExpert::class, 'enabled' => true],
    );
});

function persistedWorkflowActor(Enterprise $enterprise): User
{
    $actor = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    return $actor;
}

function persistedWorkflow(Enterprise $enterprise, string $name, string $stageKey): Workflow
{
    return app(WorkflowEntryPointService::class)->create(
        persistedWorkflowActor($enterprise),
        $enterprise,
        [
            'name' => $name,
            'purpose' => 'A workflow created entirely from persisted domain data.',
            'execution_policy' => [
                'mode' => 'deterministic',
                'requires_model_provider' => false,
            ],
            'stages' => [[
                'key' => $stageKey,
                'name' => $stageKey,
                'sequence' => 1,
                'expert_slugs' => ['business-analysis'],
                'capability_slugs' => ['business.analysis'],
                'input_contract' => [],
                'output_contract' => ['required' => ['analysis']],
            ]],
        ],
    );
}

it('executes independently configured persisted workflows through the generic engine', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = persistedWorkflowActor($enterprise);

    $first = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Persisted Workflow Alpha',
        'purpose' => 'First arbitrary persisted workflow.',
        'execution_policy' => ['mode' => 'deterministic', 'requires_model_provider' => false],
        'stages' => [[
            'key' => 'alpha-stage',
            'name' => 'Alpha stage',
            'sequence' => 1,
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'output_contract' => ['required' => ['analysis']],
        ]],
    ]);

    $second = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Persisted Workflow Beta',
        'purpose' => 'Second arbitrary persisted workflow with a different structure.',
        'execution_policy' => ['mode' => 'deterministic', 'requires_model_provider' => false],
        'stages' => [[
            'key' => 'beta-stage',
            'name' => 'Beta stage',
            'sequence' => 1,
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'output_contract' => ['required' => ['analysis']],
        ]],
    ]);

    $versions = app(WorkflowVersionService::class);
    $firstVersion = $versions->publish($first, $actor, 'publish-alpha');
    $secondVersion = $versions->publish($second, $actor, 'publish-beta');

    $firstExecution = app(WorkflowEntryPointService::class)->start(
        $actor,
        $first->refresh(),
        ['request' => 'Execute alpha.'],
        'execute-alpha',
    );

    $secondExecution = app(WorkflowEntryPointService::class)->start(
        $actor,
        $second->refresh(),
        ['request' => 'Execute beta.'],
        'execute-beta',
    );

    expect($firstExecution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($secondExecution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($firstExecution->workflow_version_id)->toBe($firstVersion->getKey())
        ->and($secondExecution->workflow_version_id)->toBe($secondVersion->getKey())
        ->and($firstExecution->outputs)->toHaveKey('alpha-stage')
        ->and($secondExecution->outputs)->toHaveKey('beta-stage')
        ->and(AgentExecution::query()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();
});

it('resumes an interrupted persisted workflow execution from durable state', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = persistedWorkflowActor($enterprise);
    $workflow = persistedWorkflow($enterprise, 'Interrupted persisted workflow', 'resume-stage');
    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'publish-resume');

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow->getKey(),
        'workflow_version_id' => $version->getKey(),
        'workflow_version' => $version->version,
        'enterprise_id' => $enterprise->getKey(),
        'actor_id' => $actor->getKey(),
        'status' => WorkflowExecution::STATUS_PENDING,
        'input' => ['request' => 'Resume this workflow.'],
    ]);

    $execution->start()->pause('Interrupted for continuation test.')->save();

    $resumed = app(WorkflowEntryPointService::class)->resume(
        $actor,
        $execution->refresh(),
        $execution->continuation_token,
    );

    expect($resumed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($resumed->workflow_version_id)->toBe($version->getKey())
        ->and($resumed->outputs)->toHaveKey('resume-stage')
        ->and($resumed->input)->toBe(['request' => 'Resume this workflow.'])
        ->and(AgentExecution::query()->count())->toBe(0);
});

it('contains no workflow-specific executable definition or resolver classes', function (): void {
    foreach ([
        'MarketingStrategyWorkflowDefinition.php',
        'StrategyCreationWorkflowDefinition.php',
        'CanonicalWorkflowProvisioner.php',
        'WorkflowTemplateResolver.php',
    ] as $file) {
        expect(file_exists(base_path('app/Services/'.$file)))->toBeFalse();
    }

    $serviceFiles = glob(base_path('app/Services/*.php')) ?: [];
    foreach ($serviceFiles as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toContain('MarketingStrategyWorkflowDefinition')
            ->not->toContain('StrategyCreationWorkflowDefinition')
            ->not->toContain('CanonicalWorkflowProvisioner')
            ->not->toContain('WorkflowTemplateResolver');
    }
});