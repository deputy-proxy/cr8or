<?php

use App\Enums\MembershipRole;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\EnterpriseContextAssembler;
use App\Services\StrategicContextService;
use Illuminate\Auth\Access\AuthorizationException;

function strategicContextActors(): array
{
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->admin()->create(['user_id' => $admin->id, 'organization_id' => $organization->id]);
    Membership::factory()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
        'role' => MembershipRole::Member,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);

    return [$organization, $admin, $member, $enterprise];
}

it('versions competitor context without turning it into a CRM subsystem', function () {
    [, $admin, , $enterprise] = strategicContextActors();
    $service = app(StrategicContextService::class);

    $v1 = $service->createCompetitor(
        $admin,
        $enterprise,
        'Competitor A',
        'https://example.test',
        'Low-cost alternative',
        ['distribution'],
        ['brand awareness'],
    );
    $v2 = $service->createCompetitor(
        $admin,
        $enterprise,
        'Competitor A',
        'https://example.test',
        'Premium alternative',
        ['brand'],
        ['price'],
    );

    expect($v1->refresh()->version)->toBe(1)
        ->and($v1->status)->toBe(Competitor::STATUS_ARCHIVED)
        ->and($v2->version)->toBe(2)
        ->and($v2->supersedes_id)->toBe($v1->id)
        ->and($enterprise->competitors()->where('is_current', true)->count())->toBe(1);
});

it('exposes mission, vision, and values from Enterprise Context through the canonical Agent boundary', function () {
    [, $admin, $member, $enterprise] = strategicContextActors();

    $context = EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->id,
        'mission' => 'Serve customers with clarity.',
        'vision' => 'Build a durable and adaptive company.',
        'values' => [
            ['name' => 'Clarity', 'description' => 'Communicate directly.'],
            ['name' => 'Trust', 'description' => 'Keep commitments.'],
        ],
    ]);

    app(StrategicContextService::class)->createCompetitor($admin, $enterprise, 'Competitor A', null, 'Known alternative');

    $strategic = app(StrategicContextService::class)->context($member, $enterprise);
    $assembled = app(EnterpriseContextAssembler::class)->assemble($member, $enterprise);
    $enterpriseData = $assembled->section('enterprise_context')?->data;

    expect($enterpriseData['context'])->toMatchArray([
        'id' => $context->id,
        'mission' => 'Serve customers with clarity.',
        'vision' => 'Build a durable and adaptive company.',
        'values' => [
            ['name' => 'Clarity', 'description' => 'Communicate directly.'],
            ['name' => 'Trust', 'description' => 'Keep commitments.'],
        ],
    ])
        ->and($strategic)->toHaveKey('competitors')
        ->and($strategic)->not->toHaveKey('mission')
        ->and($strategic)->not->toHaveKey('vision')
        ->and($enterpriseData['strategic_context']['competitors'])->toHaveCount(1);
});

it('isolates strategic context by enterprise authorization', function () {
    [, $admin, , $enterprise] = strategicContextActors();
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);

    expect(fn () => app(StrategicContextService::class)->createCompetitor($admin, $foreignEnterprise, 'No access.'))
        ->toThrow(AuthorizationException::class);

    expect($enterprise->organization_id)->not->toBe($foreignEnterprise->organization_id);
});
