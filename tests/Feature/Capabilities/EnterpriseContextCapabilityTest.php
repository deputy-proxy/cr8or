<?php

use App\Data\CapabilityInvocationRequest;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\CapabilityInvocationService;
use App\Services\EnterpriseContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('creates and retrieves Enterprise Context through the Capability boundary', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Capability Context Enterprise',
    ]);

    $created = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'enterprise.context.create',
        actor: $user,
        enterprise: $enterprise,
        inputPayload: [
            'description' => 'Persisted context for downstream runtime composition.',
            'industry' => 'Software',
            'business_model' => 'Subscription',
            'target_market' => 'SMBs',
            'geography' => 'Europe',
            'additional_context' => ['priority' => 'growth'],
        ],
        correlationId: 'enterprise-context-create-1',
        idempotencyKey: 'enterprise-context-create-1',
    ));

    expect($created['status'])->toBe('executed')
        ->and(EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeTrue();

    $retrieved = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'enterprise.context.retrieve',
        actor: $user,
        enterprise: $enterprise,
        correlationId: 'enterprise-context-retrieve-1',
        idempotencyKey: 'enterprise-context-retrieve-1',
    ));

    expect($retrieved['status'])->toBe('executed')
        ->and($retrieved['result'])->toMatchArray([
            'enterprise_id' => $enterprise->getKey(),
            'context' => [
                'description' => 'Persisted context for downstream runtime composition.',
                'industry' => 'Software',
                'business_model' => 'Subscription',
                'target_market' => 'SMBs',
                'geography' => 'Europe',
                'additional_context' => ['priority' => 'growth'],
            ],
        ]);
});

it('rejects Enterprise Context retrieval across enterprise authorization boundaries', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create();

    EnterpriseContext::factory()->create([
        'enterprise_id' => $foreignEnterprise->getKey(),
        'description' => 'Must remain isolated.',
    ]);

    expect(fn () => app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'enterprise.context.retrieve',
        actor: $user,
        enterprise: $foreignEnterprise,
    )))->toThrow(AuthorizationException::class);
});

it('reports missing Enterprise Context without fabricating downstream context', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    expect(fn () => app(EnterpriseContextService::class)->retrieve($user, $enterprise))
        ->toThrow(ModelNotFoundException::class);

    expect(EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->exists())
        ->toBeFalse();
});

it('exposes persisted Enterprise Context through the canonical runtime assembler', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'description' => 'Downstream agents must discover this from persisted context.',
        'industry' => 'Education',
    ]);

    $result = app(EnterpriseContextService::class)->retrieve($user, $enterprise);

    expect($result['context'])->toMatchArray([
        'description' => 'Downstream agents must discover this from persisted context.',
        'industry' => 'Education',
    ]);
});