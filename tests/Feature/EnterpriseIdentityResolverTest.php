<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\EnterpriseIdentityResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

it('resolves an enterprise by exact canonical slug for an authorized actor', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'valid-guide']);

    $resolved = app(EnterpriseIdentityResolver::class)->resolve($user, slug: 'valid-guide');

    expect($resolved->is($enterprise))->toBeTrue();
});

it('normalizes a named enterprise value to its canonical slug', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'name' => 'valid.guide', 'slug' => 'valid-guide']);

    $resolved = app(EnterpriseIdentityResolver::class)->resolve($user, slug: 'valid.guide');

    expect($resolved->is($enterprise))->toBeTrue();
});

it('keeps internal id lookup compatible', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'valid-guide']);

    expect(app(EnterpriseIdentityResolver::class)->resolve($user, id: $enterprise->getKey())->is($enterprise))->toBeTrue();
});

it('rejects an id and slug that identify different enterprises', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $valid = Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'valid-guide']);
    Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'blckdsgncom']);

    expect(fn () => app(EnterpriseIdentityResolver::class)->resolve($user, id: $valid->getKey(), slug: 'blckdsgncom'))
        ->toThrow(InvalidArgumentException::class, 'Enterprise identity mismatch');
});

it('rejects ambiguous slugs across organizations', function () {
    $user = User::factory()->create();
    $organizations = Organization::factory()->count(2)->create();
    foreach ($organizations as $organization) {
        Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
        Enterprise::factory()->create(['organization_id' => $organization, 'slug' => 'shared']);
    }

    expect(fn () => app(EnterpriseIdentityResolver::class)->resolve($user, slug: 'shared'))
        ->toThrow(InvalidArgumentException::class, 'ambiguous');
});

it('does not resolve an enterprise outside the actor organizations', function () {
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create(['slug' => 'valid-guide']);

    expect(fn () => app(EnterpriseIdentityResolver::class)->resolve($user, slug: 'valid-guide'))
        ->toThrow(ModelNotFoundException::class);
});

it('persists the resolved enterprise identity on Agent Assignments', function () {
    $enterprise = Enterprise::factory()->create(['slug' => 'valid-guide']);

    $assignment = \App\Models\AgentAssignment::factory()->forEnterprise($enterprise)->create();

    expect($assignment->fresh()->context['enterprise_identity'])->toBe([
        'id' => $enterprise->getKey(),
        'slug' => 'valid-guide',
    ]);
});

it('rejects an Agent Assignment with a conflicting enterprise identity', function () {
    $enterprise = Enterprise::factory()->create(['slug' => 'valid-guide']);

    expect(fn () => \App\Models\AgentAssignment::factory()->forEnterprise($enterprise)->state([
        'context' => ['enterprise_identity' => ['id' => $enterprise->getKey(), 'slug' => 'blckdsgncom']],
    ])->create())->toThrow(\LogicException::class, 'enterprise identity does not match');
});

it('persists the resolved enterprise identity on Agent Executions', function () {
    $enterprise = Enterprise::factory()->create(['slug' => 'valid-guide']);
    $assignment = \App\Models\AgentAssignment::factory()->forEnterprise($enterprise)->create();

    $execution = \App\Models\AgentExecution::factory()->forAssignment($assignment)->create();

    expect($execution->fresh()->execution_context['enterprise_identity'])->toBe([
        'id' => $enterprise->getKey(),
        'slug' => 'valid-guide',
    ]);
});

it('rejects an Agent Execution with a conflicting enterprise identity', function () {
    $enterprise = Enterprise::factory()->create(['slug' => 'valid-guide']);
    $assignment = \App\Models\AgentAssignment::factory()->forEnterprise($enterprise)->create();

    expect(fn () => \App\Models\AgentExecution::factory()->forAssignment($assignment)->state([
        'execution_context' => ['enterprise_identity' => ['id' => $enterprise->getKey(), 'slug' => 'blckdsgncom']],
    ])->create())->toThrow(\LogicException::class, 'enterprise identity does not match');
});