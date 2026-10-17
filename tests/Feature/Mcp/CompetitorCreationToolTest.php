<?php

use App\Capabilities\CapabilityRegistry;
use App\Experts\MarketingExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateCompetitorTool;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Operations\CreateCompetitor;
use App\Services\ExpertCapabilityResolver;
use App\Services\StrategicContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

function competitorToolOwner(Organization $organization): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    return $user;
}

it('registers the competitor create capability on the Marketing Expert through one Operation and Tool', function () {
    $definition = app(CapabilityRegistry::class)->forTool(CreateCompetitorTool::class);

    expect($definition->key)->toBe('marketing.competitor.create')
        ->and($definition->operation)->toBe(CreateCompetitor::class)
        ->and($definition->tool)->toBe('create-competitor')
        ->and(app(MarketingExpert::class)->capabilities())->toContain('marketing.competitor.create')
        ->and(app(ExpertCapabilityResolver::class)->resolve('marketing', 'marketing.competitor.create')->operation)->toBe(CreateCompetitor::class)
        ->and(app(App\Experts\CopywritingExpert::class)->capabilities())->not->toContain('marketing.competitor.create');

    expect(fn () => app(ExpertCapabilityResolver::class)->resolve('copywriting', 'marketing.competitor.create'))
        ->toThrow(AuthorizationException::class);
});

it('creates a persisted enterprise-scoped competitor through the governed MCP boundary', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => '  Competitor Alpha  ',
            'website' => 'https://alpha.example.test',
            'positioning' => 'Value-focused alternative',
            'strengths' => ['distribution', 'price'],
            'weaknesses' => ['brand awareness'],
        ])
        ->assertOk()
        ->assertSee(['Competitor Alpha', 'Value-focused alternative', 'distribution']);

    $competitor = Competitor::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    expect($competitor->name)->toBe('Competitor Alpha')
        ->and($competitor->version)->toBe(1)
        ->and($competitor->website)->toBe('https://alpha.example.test')
        ->and($competitor->strengths)->toBe(['distribution', 'price'])
        ->and($competitor->weaknesses)->toBe(['brand awareness'])
        ->and($competitor->is_current)->toBeTrue();
});

it('versions a repeated competitor name without overwriting historical data', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(CreateCompetitorTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Competitor Beta',
        'positioning' => 'Original position',
    ])->assertOk();

    $first = Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Competitor Beta')->firstOrFail();

    $server->tool(CreateCompetitorTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Competitor Beta',
        'positioning' => 'Updated position',
    ])->assertOk();

    $second = Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Competitor Beta')->where('is_current', true)->firstOrFail();

    expect($first->refresh()->version)->toBe(1)
        ->and($first->status)->toBe(Competitor::STATUS_ARCHIVED)
        ->and($first->positioning)->toBe('Original position')
        ->and($second->version)->toBe(2)
        ->and($second->supersedes_id)->toBe($first->getKey())
        ->and($second->positioning)->toBe('Updated position')
        ->and(Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Competitor Beta')->count())->toBe(2);
});

it('denies creation under an enterprise outside the authenticated user organization', function () {
    $actorOrganization = Organization::factory()->create();
    $actor = competitorToolOwner($actorOrganization);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $foreignEnterprise->getKey(),
            'name' => 'Must not persist',
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $foreignEnterprise->getKey())->exists())->toBeFalse();
});

it('denies a member without enterprise create permission and does not leak competitor data', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $member->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => 'member',
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($member, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Unauthorized competitor',
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('rejects invalid URLs and malformed strength lists before persistence', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Invalid competitor',
            'website' => 'not a URL',
            'strengths' => ['valid', ['malformed']],
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('enforces create policy and domain validation when the Operation is called without the MCP adapter', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    expect(fn () => app(CreateCompetitor::class)->execute($actor, [
        'enterprise' => $enterprise,
        'name' => 'Invalid direct call',
        'website' => 'not a URL',
    ]))->toThrow(ValidationException::class);

    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    expect(fn () => app(StrategicContextService::class)->createCompetitor($actor, $foreignEnterprise, 'Forbidden'))
        ->toThrow(AuthorizationException::class);
});