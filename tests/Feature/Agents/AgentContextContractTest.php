<?php

use App\Data\AgentContext;
use App\Data\AgentContextSection;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

it('defines a provider-neutral context contract with deterministic section ordering and metadata', function () {
    $context = new AgentContext([
        'work' => new AgentContextSection(
            name: 'work',
            data: ['work_items' => []],
            source: 'work.provider',
            scope: ['organization_id' => 1, 'enterprise_id' => 2],
            relevance: 'current work',
        ),
        'enterprise_context' => new AgentContextSection(
            name: 'enterprise_context',
            data: ['description' => 'Test'],
            source: 'enterprise.context',
            scope: ['organization_id' => 1, 'enterprise_id' => 2],
        ),
        'enterprise' => new AgentContextSection(
            name: 'enterprise',
            data: ['id' => 2, 'name' => 'Test'],
            source: 'enterprise',
            scope: ['organization_id' => 1, 'enterprise_id' => 2],
        ),
        'decisions' => new AgentContextSection(
            name: 'decisions',
            data: [],
            source: 'decision.history',
            scope: ['organization_id' => 1, 'enterprise_id' => 2],
        ),
    ]);

    expect(array_map(
        fn (AgentContextSection $section) => $section->name,
        $context->sections(),
    ))->toBe([
        'enterprise',
        'enterprise_context',
        'work',
        'decisions',
    ])
        ->and($context->metadata()['work'])->toMatchArray([
            'source' => 'work.provider',
            'scope' => ['organization_id' => 1, 'enterprise_id' => 2],
            'relevance' => 'current work',
        ]);
});

it('keeps persistent Agent memory outside the current context contract', function () {
    $context = new AgentContext([
        'enterprise' => new AgentContextSection(
            name: 'enterprise',
            data: ['id' => 1],
            source: 'enterprise',
            scope: ['organization_id' => 1, 'enterprise_id' => 1],
        ),
    ]);

    expect($context->toArray())->not->toHaveKey('memory')
        ->and($context->metadata())->not->toHaveKey('memory');
});

it('assembles an authorization-scoped context contract for Agent execution', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'description' => 'Authorized enterprise context.',
    ]);

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise', 'knowledge', 'strategy', 'work'],
    );

    expect($context)->toBeInstanceOf(AgentContext::class)
        ->and($context->sections())->toHaveCount(5)
        ->and($context->section('enterprise')?->data['id'])->toBe($enterprise->getKey())
        ->and($context->section('enterprise_context')?->data['context']['description'])->toBe('Authorized enterprise context.')
        ->and($context->metadata()['enterprise']['scope'])->toBe([
            'organization_id' => $organization->getKey(),
            'enterprise_id' => $enterprise->getKey(),
        ])
        ->and($context->toArray()['enterprise']['enterprise']['id'])->toBe($enterprise->getKey());
});

it('denies a context contract for an Enterprise outside the user organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create([
        'organization_id' => $foreignOrganization->getKey(),
    ]);

    expect(fn () => app(McpContextAssembler::class)->forAgent(
        $user,
        $foreignEnterprise,
        ['enterprise'],
    ))->toThrow(AuthorizationException::class);
});

it('does not require optional context sections to be present', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $context = app(McpContextAssembler::class)->forAgent(
        $user,
        $enterprise,
        ['enterprise'],
    );

    expect($context->has('knowledge'))->toBeFalse()
        ->and($context->has('strategy'))->toBeFalse()
        ->and($context->has('work'))->toBeFalse()
        ->and($context->has('decisions'))->toBeFalse()
        ->and($context->has('execution_history'))->toBeFalse();
});
