<?php

use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

beforeEach(function (): void {
    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'business-analysis',
    ], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

function continuationActor(Enterprise $enterprise): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $enterprise->organization_id,
    ]);

    return $user;
}

function continuationWorkflow(Enterprise $enterprise, User $actor, array $stageDefinitions): Workflow
{
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    $version = WorkflowVersionService::class;

    \App\Models\WorkflowStage::factory()->createMany(array_map(
        fn (array $stage): array => [
            'workflow_id' => $workflow->id,
            'key' => $stage['key'],
            'name' => $stage['name'] ?? $stage['key'],
            'sequence' => $stage['sequence'] ?? 1,
            'dependencies' => $stage['dependencies'] ?? [],
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'input_contract' => $stage['input_contract'] ?? [],
            'output_contract' => ['required' => ['analysis']],
        ],
        $stageDefinitions,
    ));

    app($version)->publish($workflow, $actor, 'continuation-publish:'.$workflow->id);

    return $workflow->refresh();
}

it('propagates stage outputs through explicit input mappings', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = continuationActor($enterprise);
    $workflow = continuationWorkflow($enterprise, $actor, [
        ['key' => 'research', 'name' => 'Research'],
        [
            'key' => 'strategy',
            'name' => 'Strategy',
            'sequence' => 2,
            'dependencies' => ['research'],
            'input_contract' => [
                'required' => ['mapped_request'],
                'mappings' => ['mapped_request' => 'stages.research.analysis'],
            ],
        ],
    ]);

    $execution = app(WorkflowExecutionService::class)->start(
        $actor,
        $workflow,
        ['request' => 'Analyze the enterprise.'],
        'mapping-test',
    );

    expect($execution->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($execution->outputs)->toHaveKeys(['research', 'strategy'])
        ->and($execution->context['stages'])->toHaveKey('research');
});

it('rejects stale continuation tokens after the execution advances', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = continuationActor($enterprise);
    $workflow = continuationWorkflow($enterprise, $actor, [['key' => 'research', 'name' => 'Research']]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $workflow->published_version_id,
        'workflow_version' => $workflow->publishedVersion->version,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
        'status' => WorkflowExecution::STATUS_PENDING,
    ]);

    $staleToken = $execution->continuation_token;
    $service = app(WorkflowExecutionService::class);
    $service->continue($actor, $execution, $staleToken);

    expect(fn () => $service->continue($actor, $execution->refresh(), $staleToken))
        ->toThrow(AuthorizationException::class, 'stale or invalid');
});

it('does not fail an execution when a second continuation observes it already running', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = continuationActor($enterprise);
    $workflow = continuationWorkflow($enterprise, $actor, [['key' => 'research', 'name' => 'Research']]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $workflow->published_version_id,
        'workflow_version' => $workflow->publishedVersion->version,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
        'status' => WorkflowExecution::STATUS_RUNNING,
    ]);

    expect(fn () => app(WorkflowExecutionService::class)->continue($actor, $execution, $execution->continuation_token))
        ->toThrow(LogicException::class, 'already running');

    expect($execution->refresh()->status)->toBe(WorkflowExecution::STATUS_RUNNING);
});

it('retries a failed execution using the same durable stage idempotency boundary', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = continuationActor($enterprise);
    $workflow = continuationWorkflow($enterprise, $actor, [['key' => 'research', 'name' => 'Research']]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $workflow->published_version_id,
        'workflow_version' => $workflow->publishedVersion->version,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
        'status' => WorkflowExecution::STATUS_FAILED,
        'failure_reason' => 'Transient failure.',
    ]);

    $completed = app(WorkflowExecutionService::class)->continue($actor, $execution, $execution->continuation_token);

    expect($completed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($completed->failure_reason)->toBeNull();
});

it('resumes waiting-for-input state without losing execution state', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = continuationActor($enterprise);
    $workflow = continuationWorkflow($enterprise, $actor, [['key' => 'research', 'name' => 'Research']]);

    $execution = WorkflowExecution::factory()->create([
        'workflow_id' => $workflow,
        'workflow_version_id' => $workflow->published_version_id,
        'workflow_version' => $workflow->publishedVersion->version,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $actor->id,
        'status' => WorkflowExecution::STATUS_PENDING,
    ]);

    $execution->start()->waitForInput('Human input required.')->save();
    $token = $execution->continuation_token;

    $completed = app(WorkflowExecutionService::class)->continue($actor, $execution, $token);

    expect($completed->status)->toBe(WorkflowExecution::STATUS_COMPLETED)
        ->and($completed->correlation_id)->not->toBeEmpty();
});