<?php

use App\Models\AgentDecision;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('persists an attributable execution with an explicit lifecycle', function () {
    $organization = Organization::factory()->create(['name' => 'Acme']);
    $actor = User::factory()->create(['name' => 'Agent Actor']);
    $descriptor = AgentDescriptor::factory()->create(['slug' => 'planner']);

    $execution = AgentExecution::factory()->create([
        'organization_id' => $organization,
        'actor_id' => $actor,
        'agent_descriptor_id' => $descriptor,
        'organization_name' => $organization->name,
        'actor_name' => $actor->name,
        'requested_at' => Carbon::parse('2026-09-22 10:00:00'),
    ]);

    $execution->start()->save();
    $execution->succeed()->save();
    $execution->refresh();

    expect($execution->status)->toBe(AgentExecution::STATUS_SUCCEEDED)
        ->and($execution->actor->is($actor))->toBeTrue()
        ->and($execution->agentDescriptor->is($descriptor))->toBeTrue()
        ->and($execution->organization->is($organization))->toBeTrue()
        ->and($execution->requested_at->equalTo(Carbon::parse('2026-09-22 10:00:00')))->toBeTrue()
        ->and($execution->started_at)->not->toBeNull()
        ->and($execution->completed_at)->not->toBeNull();
});

it('allows an execution to fail but never transition from failed to succeeded', function () {
    $execution = AgentExecution::factory()->create();

    $execution->fail('Provider unavailable')->save();
    expect($execution->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and($execution->completed_at)->not->toBeNull();

    expect(fn () => $execution->succeed()->save())
        ->toThrow(LogicException::class, 'cannot transition from [failed] to [succeeded]');

    $execution->refresh();

    expect($execution->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and($execution->failure_reason)->toBe('Provider unavailable');
});

it('prevents historical attribution and timestamps from being rewritten', function () {
    $actor = User::factory()->create(['name' => 'Original Actor']);
    $descriptor = AgentDescriptor::factory()->create(['slug' => 'original-agent']);
    $requestedAt = Carbon::parse('2026-09-22 11:00:00');

    $execution = AgentExecution::factory()->create([
        'actor_id' => $actor,
        'actor_name' => $actor->name,
        'agent_descriptor_id' => $descriptor,
        'agent_slug' => $descriptor->slug,
        'agent_runtime_class' => $descriptor->runtime_class,
        'requested_at' => $requestedAt,
    ]);

    $execution->actor_name = 'Changed Actor';
    $execution->agent_slug = 'changed-agent';
    $execution->requested_at = Carbon::parse('2026-09-23 11:00:00');
    $execution->status = AgentExecution::STATUS_EXECUTING;
    $execution->save();
    $execution->refresh();

    expect($execution->actor_name)->toBe('Original Actor')
        ->and($execution->agent_slug)->toBe('original-agent')
        ->and($execution->requested_at->equalTo($requestedAt))->toBeTrue()
        ->and($execution->status)->toBe(AgentExecution::STATUS_EXECUTING);
});

it('keeps execution scope aligned to its organization and enterprise', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $execution = AgentExecution::factory()->create([
        'organization_id' => $organization,
        'enterprise_id' => $enterprise,
    ]);

    $execution->enterprise_id = $otherEnterprise->id;

    expect(fn () => $execution->save())
        ->toThrow(LogicException::class, 'enterprise must belong to its organization');
});

it('records decisions with agent, actor and execution attribution', function () {
    $execution = AgentExecution::factory()->forEnterprise()->create();
    $decision = AgentDecision::factory()->forExecution($execution)->create([
        'title' => 'Approve campaign',
        'summary' => 'The campaign meets the configured criteria.',
        'decided_at' => Carbon::parse('2026-09-22 12:30:00'),
    ]);

    $decision->refresh();

    expect($decision->execution->is($execution))->toBeTrue()
        ->and($decision->agent_slug)->toBe($execution->agent_slug)
        ->and($decision->agent_runtime_class)->toBe($execution->agent_runtime_class)
        ->and($decision->actor_name)->toBe($execution->actor_name)
        ->and($decision->decided_at->equalTo(Carbon::parse('2026-09-22 12:30:00')))->toBeTrue()
        ->and($decision->enterprise_id)->toBe($execution->enterprise_id);
});

it('preserves decision history when runtime configuration changes', function () {
    $execution = AgentExecution::factory()->create();
    $decision = AgentDecision::factory()->forExecution($execution)->create([
        'decided_at' => Carbon::parse('2026-09-22 13:00:00'),
    ]);

    $decision->agent_slug = 'replacement-agent';
    $decision->actor_name = 'Replacement Actor';
    $decision->decided_at = Carbon::parse('2026-09-23 13:00:00');
    $decision->title = 'Clarified title';
    $decision->save();
    $decision->refresh();

    expect($decision->agent_slug)->toBe($execution->agent_slug)
        ->and($decision->actor_name)->toBe($execution->actor_name)
        ->and($decision->decided_at->equalTo(Carbon::parse('2026-09-22 13:00:00')))->toBeTrue()
        ->and($decision->title)->toBe('Clarified title');
});

it('enforces organization isolation and denies destructive history mutations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $foreignMember = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $foreignMember, 'organization_id' => $otherOrganization]);

    $execution = AgentExecution::factory()->create(['organization_id' => $organization]);
    $decision = AgentDecision::factory()->forExecution($execution)->create();

    expect(Gate::forUser($owner)->allows('view', $execution))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $execution))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $execution))->toBeFalse()
        ->and(Gate::forUser($foreignMember)->allows('view', $execution))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $decision))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $decision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $decision))->toBeFalse()
        ->and(Gate::forUser($foreignMember)->allows('view', $decision))->toBeFalse();
});

it('keeps historical record schemas and relationships focused', function () {
    expect(Schema::getColumnListing('agent_executions'))->toBe([
        'id', 'organization_id', 'enterprise_id', 'agent_descriptor_id', 'agent_assignment_id',
        'actor_id', 'organization_name', 'enterprise_name', 'agent_slug', 'agent_runtime_class',
        'actor_name', 'status', 'requested_at', 'started_at', 'completed_at', 'failure_reason',
        'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('agent_decisions'))->toBe([
        'id', 'organization_id', 'enterprise_id', 'execution_id', 'agent_descriptor_id', 'actor_id',
        'organization_name', 'enterprise_name', 'agent_slug', 'agent_runtime_class', 'actor_name',
        'title', 'summary', 'rationale', 'decided_at', 'created_at', 'updated_at',
    ]);
});
