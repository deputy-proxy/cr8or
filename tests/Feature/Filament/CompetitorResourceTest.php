<?php

use App\Filament\Resources\Competitors\CompetitorResource;
use App\Filament\Resources\Competitors\Pages\EditCompetitor;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\StrategicContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;

function competitorResourceActors(): array
{
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner->id, 'organization_id' => $organization->id]);
    Membership::factory()->admin()->create(['user_id' => $admin->id, 'organization_id' => $organization->id]);
    Membership::factory()->create(['user_id' => $member->id, 'organization_id' => $organization->id, 'role' => 'member']);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);

    return [$organization, $foreignOrganization, $owner, $admin, $member, $enterprise, $foreignEnterprise];
}

function createResourceCompetitor(User $actor, Enterprise $enterprise, string $name = 'Competitor A'): Competitor
{
    return app(StrategicContextService::class)->createCompetitor(
        $actor,
        $enterprise,
        $name,
        'https://example.test',
        'Initial positioning',
        ['distribution'],
        ['brand awareness'],
    );
}

it('registers individual view and revision routes without a hard-delete route', function () {
    expect(array_keys(CompetitorResource::getPages()))->toBe(['index', 'create', 'view', 'edit'])
        ->and(Route::has('filament.admin.resources.competitors.view'))->toBeTrue()
        ->and(Route::has('filament.admin.resources.competitors.edit'))->toBeTrue()
        ->and(Route::has('filament.admin.resources.competitors.delete'))->toBeFalse();
});

it('allows viewing to authorized organization members but restricts revision and archive to owners and admins', function () {
    [, , $owner, $admin, $member, $enterprise] = competitorResourceActors();
    $competitor = createResourceCompetitor($owner, $enterprise);

    expect(Gate::forUser($owner)->allows('view', $competitor))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $competitor))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('archive', $competitor))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $competitor))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $competitor))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $competitor))->toBeFalse()
        ->and(Gate::forUser($member)->allows('archive', $competitor))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $competitor))->toBeFalse();
});

it('creates a successor version on revision and preserves lineage when the name changes', function () {
    [, , $owner, , , $enterprise] = competitorResourceActors();
    $original = createResourceCompetitor($owner, $enterprise);
    $other = createResourceCompetitor($owner, $enterprise, 'Competitor B');

    $successor = app(StrategicContextService::class)->reviseCompetitor(
        $owner,
        $original,
        'Renamed Competitor',
        'https://renamed.example.test',
        'Updated positioning',
        ['product'],
        ['price'],
    );

    expect($original->refresh()->status)->toBe(Competitor::STATUS_ARCHIVED)
        ->and($original->is_current)->toBeFalse()
        ->and($original->effective_to)->not->toBeNull()
        ->and($successor->name)->toBe('Renamed Competitor')
        ->and($successor->version)->toBeGreaterThan($other->version)
        ->and($successor->supersedes_id)->toBe($original->id)
        ->and($successor->is_current)->toBeTrue()
        ->and($enterprise->competitors()->where('is_current', true)->count())->toBe(2);
});

it('rejects revision of an archived predecessor and conflicting current names', function () {
    [, , $owner, , , $enterprise] = competitorResourceActors();
    $original = createResourceCompetitor($owner, $enterprise);
    createResourceCompetitor($owner, $enterprise, 'Competitor B');

    expect(fn () => app(StrategicContextService::class)->reviseCompetitor($owner, $original, 'Competitor B'))
        ->toThrow(ValidationException::class);

    $successor = app(StrategicContextService::class)->reviseCompetitor($owner, $original, 'Competitor A v2');

    expect(fn () => app(StrategicContextService::class)->reviseCompetitor($owner, $original, 'Stale edit'))
        ->toThrow(AuthorizationException::class)
        ->and($successor->supersedes_id)->toBe($original->id);
});

it('rejects cross-enterprise or non-ascending competitor lineage at the model boundary', function () {
    [, $foreignOrganization, $owner, , , $enterprise, $foreignEnterprise] = competitorResourceActors();
    $foreignOwner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $foreignOwner->id, 'organization_id' => $foreignOrganization->id]);
    $foreignCompetitor = createResourceCompetitor($foreignOwner, $foreignEnterprise);

    expect(fn () => Competitor::query()->create([
        'enterprise_id' => $enterprise->id,
        'version' => 1,
        'name' => 'Invalid cross-enterprise successor',
        'status' => Competitor::STATUS_ACTIVE,
        'effective_from' => Carbon::now(),
        'is_current' => true,
        'supersedes_id' => $foreignCompetitor->id,
    ]))->toThrow(LogicException::class);
});

it('archives a current version without deleting history and rejects repeated archive', function () {
    [, , $owner, , , $enterprise] = competitorResourceActors();
    $competitor = createResourceCompetitor($owner, $enterprise);

    $archived = app(StrategicContextService::class)->archiveCompetitor($owner, $competitor);

    expect($archived->status)->toBe(Competitor::STATUS_ARCHIVED)
        ->and($archived->is_current)->toBeFalse()
        ->and($archived->effective_to)->not->toBeNull()
        ->and(Competitor::query()->whereKey($competitor->id)->exists())->toBeTrue()
        ->and($enterprise->competitors()->where('is_current', true)->count())->toBe(0)
        ->and(Gate::forUser($owner)->allows('delete', $competitor))->toBeFalse()
        ->and(fn () => app(StrategicContextService::class)->archiveCompetitor($owner, $competitor))->toThrow(AuthorizationException::class);
});

it('enforces enterprise scope on individual view routes and domain actions', function () {
    [, $foreignOrganization, $owner, , , $enterprise, $foreignEnterprise] = competitorResourceActors();
    $foreignOwner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $foreignOwner->id, 'organization_id' => $foreignOrganization->id]);
    $competitor = createResourceCompetitor($foreignOwner, $foreignEnterprise);

    $this->actingAs($owner)
        ->get(CompetitorResource::getUrl('view', ['record' => $competitor]))
        ->assertNotFound();

    expect(fn () => app(StrategicContextService::class)->reviseCompetitor($owner, $competitor, 'Unauthorized rename'))
        ->toThrow(AuthorizationException::class)
        ->and($enterprise->competitors()->count())->toBe(0);
});

it('renders an authorized competitor detail page with lifecycle and lineage information', function () {
    [, , $owner, , , $enterprise] = competitorResourceActors();
    $competitor = createResourceCompetitor($owner, $enterprise);

    $this->actingAs($owner)
        ->get(CompetitorResource::getUrl('view', ['record' => $competitor]))
        ->assertOk()
        ->assertSee('Competitor details')
        ->assertSee('Initial positioning')
        ->assertSee('Version history')
        ->assertSee('Current');
});

it('saves the Filament edit form as a successor and redirects to the successor view', function () {
    [, , $owner, , , $enterprise] = competitorResourceActors();
    $competitor = createResourceCompetitor($owner, $enterprise);
    $this->actingAs($owner);

    Livewire::test(EditCompetitor::class, ['record' => $competitor->getRouteKey()])
        ->fillForm([
            'enterprise_id' => $enterprise->id,
            'name' => 'Competitor A revised',
            'website' => 'https://revised.example.test',
            'positioning' => 'Revised positioning',
            'strengths' => ['product'],
            'weaknesses' => ['pricing'],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(CompetitorResource::getUrl('view', ['record' => Competitor::query()->where('name', 'Competitor A revised')->firstOrFail()]));

    expect($competitor->refresh()->is_current)->toBeFalse()
        ->and(Competitor::query()->where('name', 'Competitor A revised')->where('is_current', true)->exists())->toBeTrue();
});
