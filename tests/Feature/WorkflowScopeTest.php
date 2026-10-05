<?php

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use App\Models\WorkflowExecution;
use App\Services\WorkflowEntryPointService;
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

function scopeActorForEnterprises(Enterprise ...$enterprises): User
{
    $actor = User::factory()->create();

    foreach ($enterprises as $enterprise) {
        Membership::factory()->create([
            'user_id' => $actor,
            'organization_id' => $enterprise->organization_id,
            'role' => MembershipRole::Owner,
        ]);
    }

    return $actor;
}

function scopeStage(Workflow $workflow): WorkflowStage
{
    return WorkflowStage::factory()->create([
        'workflow_id' => $workflow,
        'key' => 'analysis',
        'name' => 'Analysis',
        'sequence' => 1,
        'dependencies' => [],
        'expert_slugs' => ['business-analysis'],
        'capability_slugs' => ['business.analysis'],
        'input_contract' => ['required' => ['request']],
        'output_contract' => ['required' => ['analysis']],
    ]);
}

it('enforces the generic and enterprise-specific scope invariant', function (): void {
    $enterprise = Enterprise::factory()->create();

    $generic = Workflow::factory()->generic()->create();
    expect($generic->enterprise_id)->toBeNull()
        ->and($generic->enterprise_specific)->toBeFalse();

    expect(fn () => Workflow::factory()->create([
        'enterprise_specific' => true,
        'enterprise_id' => null,
    ]))->toThrow(LogicException::class);

    expect(fn () => Workflow::factory()->create([
        'enterprise_specific' => false,
        'enterprise_id' => $enterprise,
    ]))->toThrow(LogicException::class);
});


it('allows an existing unversioned workflow to change scope', function (): void {
    $enterpriseA = Enterprise::factory()->create();
    $enterpriseB = Enterprise::factory()->create();

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterpriseA,
        'enterprise_specific' => true,
    ]);

    $workflow->changeScope(false);

    expect($workflow->refresh()->enterprise_specific)->toBeFalse()
        ->and($workflow->enterprise_id)->toBeNull();

    $workflow->changeScope(true, $enterpriseB);

    expect($workflow->refresh()->enterprise_specific)->toBeTrue()
        ->and($workflow->enterprise_id)->toBe($enterpriseB->getKey());
});

it('rejects scope changes once workflow versions or executions exist', function (): void {
    $enterprise = Enterprise::factory()->create();

    $workflow = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'enterprise_specific' => true,
    ]);

    \Illuminate\Support\Facades\DB::table('workflow_versions')->insert([
        'workflow_id' => $workflow->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'version' => 1,
        'status' => 'draft',
        'name' => 'Test version',
        'stage_definitions' => json_encode([]),
        'created_by' => User::factory()->create()->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $workflow->changeScope(false))
        ->toThrow(LogicException::class, 'Workflow scope cannot be changed');

    $workflowWithoutVersion = Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'enterprise_specific' => true,
    ]);

    expect($workflowWithoutVersion->versions()->exists())->toBeFalse();

        expect(fn () => $workflowWithoutVersion->changeScope(false))
        ->toThrow(LogicException::class, 'Workflow scope cannot be changed');
});