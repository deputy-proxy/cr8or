<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateWorkflowTool;
use App\Mcp\Tools\UpdateWorkflowTool;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowVersionService;
use Illuminate\Support\Facades\Queue;

function workflowUpdateOwner(User $user, Organization $organization): void
{
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
}

function workflowUpdateStages(string $stageKey = 'research'): array
{
    return [[
        'key' => $stageKey,
        'name' => ucfirst($stageKey),
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'input_contract' => ['required' => ['request']],
        'output_contract' => ['required' => ['analysis']],
    ]];
}

beforeEach(function (): void {
    Queue::fake();

    ExpertDescriptor::query()->updateOrCreate([
        'slug' => 'business-analysis',
    ], [
        'runtime_class' => \App\Experts\BusinessAnalysisExpert::class,
        'enabled' => true,
    ]);
});

it('registers the workflow update MCP entry point', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowUpdateOwner($user, $organization);

    Cr8orServer::actingAs($user, 'api')->tools()->assertRegistered([
        UpdateWorkflowTool::class,
    ]);
});

it('updates workflow metadata and stages through the governed MCP capability', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowUpdateOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(CreateWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'name' => 'Original workflow',
        'stages' => workflowUpdateStages(),
    ])->assertOk();

    $workflow = Workflow::query()->where('name', 'Original workflow')->firstOrFail();

    $server->tool(UpdateWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'name' => 'Updated workflow',
        'canonical_key' => 'workflow.updated',
        'purpose' => 'Updated through MCP.',
        'execution_policy' => [
            'mode' => 'deterministic',
            'requires_model_provider' => false,
        ],
        'stages' => workflowUpdateStages('analysis'),
    ])->assertOk();

    $workflow->refresh();

    expect($workflow->name)->toBe('Updated workflow')
        ->and($workflow->canonical_key)->toBe('workflow.updated')
        ->and($workflow->purpose)->toBe('Updated through MCP.')
        ->and($workflow->stages()->count())->toBe(1)
        ->and($workflow->stages()->firstOrFail()->key)->toBe('analysis')
        ->and($workflow->stages()->firstOrFail()->capability_input_contract)->not->toBe([])
        ->and($workflow->published_version_id)->toBeNull();
});

it('keeps the immutable published version until the edited workflow is republished', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    workflowUpdateOwner($actor, $organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $workflow = app(\App\Services\WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Published workflow',
        'stages' => workflowUpdateStages(),
    ]);

    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'publish-before-update');

    Cr8orServer::actingAs($actor, 'api')->tool(UpdateWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'name' => 'Edited after publication',
        'stages' => workflowUpdateStages('edited'),
    ])->assertOk();

    expect($workflow->refresh()->published_version_id)->toBe($version->id)
        ->and($version->refresh()->status)->toBe('published')
        ->and($version->name)->toBe('Published workflow')
        ->and($version->stage_definitions[0]['key'])->toBe('research');
});

it('rejects workflow updates for non-managers', function (): void {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->id,
        'name' => 'Protected workflow',
    ]);

    Cr8orServer::actingAs($actor, 'api')->tool(UpdateWorkflowTool::class, [
        'enterprise_id' => $enterprise->id,
        'workflow_id' => $workflow->id,
        'name' => 'Should fail',
    ])->assertHasErrors();

    expect($workflow->refresh()->name)->toBe('Protected workflow');
});
