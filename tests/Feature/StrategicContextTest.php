<?php

use App\Enums\MembershipRole;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vision;
use App\Services\EnterpriseContextAssembler;
use App\Services\StrategicContextService;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

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

it('creates versioned vision and mission records with authoritative current versions', function () {
    [, $admin, , $enterprise] = strategicContextActors();
    $service = app(StrategicContextService::class);

    $visionV1 = $service->createVision($admin, $enterprise, 'Build a durable company.');
    $visionV2 = $service->createVision($admin, $enterprise, 'Build a durable and adaptive company.');
    $missionV1 = $service->createMission($admin, $enterprise, 'Serve customers with clarity.');
    $missionV2 = $service->createMission($admin, $enterprise, 'Serve customers with clarity and speed.');

    expect($visionV1->refresh()->version)->toBe(1)
        ->and($visionV1->status)->toBe(Vision::STATUS_ARCHIVED)
        ->and($visionV1->is_current)->toBeFalse()
        ->and($visionV2->version)->toBe(2)
        ->and($visionV2->supersedes_id)->toBe($visionV1->id)
        ->and($visionV2->is_current)->toBeTrue()
        ->and($missionV1->refresh()->status)->toBe(Mission::STATUS_ARCHIVED)
        ->and($missionV2->version)->toBe(2)
        ->and($enterprise->visions()->where('is_current', true)->count())->toBe(1)
        ->and($enterprise->missions()->where('is_current', true)->count())->toBe(1);
});

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

it('prevents rewriting historical strategic records', function () {
    [, $admin, , $enterprise] = strategicContextActors();
    $vision = app(StrategicContextService::class)->createVision($admin, $enterprise, 'Original vision.');

    expect(fn () => $vision->update(['statement' => 'Rewritten history.']))
        ->toThrow(LogicException::class, 'Vision history is immutable; create a new version instead.');
});

it('exposes current strategic context through the canonical enterprise context boundary', function () {
    [, $admin, $member, $enterprise] = strategicContextActors();
    $service = app(StrategicContextService::class);

    $service->createVision($admin, $enterprise, 'Current vision.');
    $service->createMission($admin, $enterprise, 'Current mission.');
    $service->createCompetitor($admin, $enterprise, 'Competitor A', null, 'Known alternative');

    $strategic = $service->context($member, $enterprise);
    $context = app(EnterpriseContextAssembler::class)->assemble($member, $enterprise);
    $enterpriseData = $context->section('enterprise_context')?->data;

    expect($strategic['vision']['statement'])->toBe('Current vision.')
        ->and($strategic['mission']['statement'])->toBe('Current mission.')
        ->and($strategic['competitors'][0]['name'])->toBe('Competitor A')
        ->and($enterpriseData['strategic_context']['vision']['statement'])->toBe('Current vision.')
        ->and($enterpriseData['strategic_context']['mission']['statement'])->toBe('Current mission.')
        ->and($enterpriseData['strategic_context']['competitors'])->toHaveCount(1);
});

it('isolates strategic context by enterprise authorization', function () {
    [$organization, $admin, , $enterprise] = strategicContextActors();
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);

    expect(fn () => app(StrategicContextService::class)->createVision($admin, $foreignEnterprise, 'No access.'))
        ->toThrow(AuthorizationException::class);

    expect($enterprise->organization_id)->not->toBe($foreignEnterprise->organization_id);
});

it('keeps strategic version history queryable after current context changes', function () {
    [, $admin, , $enterprise] = strategicContextActors();
    $service = app(StrategicContextService::class);

    $first = $service->createVision($admin, $enterprise, 'First');
    $second = $service->createVision($admin, $enterprise, 'Second');
    $third = $service->createVision($admin, $enterprise, 'Third');

    expect(Vision::query()->where('enterprise_id', $enterprise->id)->orderBy('version')->pluck('statement')->all())
        ->toBe(['First', 'Second', 'Third'])
        ->and($third->supersedes_id)->toBe($second->id)
        ->and($second->supersedes_id)->toBe($first->id);
});