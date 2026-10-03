<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\Gate;

it('authorizes workflow execution access by organization membership', function (): void {
    $member = User::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreign = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise->id]);
    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow->id,
        'enterprise_id' => $enterprise->id,
        'status' => WorkflowVersion::STATUS_PUBLISHED,
        'stage_definitions' => [],
    ]);

    $execution = WorkflowExecution::query()->create([
        'workflow_id' => $workflow->id,
        'workflow_version_id' => $version->id,
        'workflow_version' => $version->version,
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->id,
        'actor_id' => $member->id,
        'status' => WorkflowExecution::STATUS_PENDING,
        'correlation_id' => fake()->uuid(),
        'idempotency_key' => fake()->unique()->uuid(),
        'continuation_token' => fake()->uuid(),
        'input' => [],
        'outputs' => [],
        'context' => [],
    ]);

    Membership::factory()->owner()->create([
        'user_id' => $member,
        'organization_id' => $enterprise->organization_id,
    ]);
    Membership::factory()->owner()->create([
        'user_id' => $foreign,
        'organization_id' => $foreignEnterprise->organization_id,
    ]);

    expect(Gate::forUser($member)->allows('view', $execution))->toBeTrue()
        ->and(Gate::forUser($member)->allows('resume', $execution))->toBeTrue()
        ->and(Gate::forUser($foreign)->allows('view', $execution))->toBeFalse()
        ->and(Gate::forUser($foreign)->allows('resume', $execution))->toBeFalse();
});
