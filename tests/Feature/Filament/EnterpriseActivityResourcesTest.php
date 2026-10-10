<?php

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Issues\IssueResource;
use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('lists only authorized Enterprise activity and keeps Events read-only', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);

    $visibleEvent = Event::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'description' => 'Visible enterprise activity',
        'event_type' => 'enterprise_updated',
        'source' => Event::SOURCE_CR8OR,
    ]);
    $foreignEvent = Event::factory()->create([
        'organization_id' => $foreignOrganization->id,
        'enterprise_id' => $foreignEnterprise->id,
        'description' => 'Foreign enterprise activity must not leak',
    ]);

    // Simulate inconsistent historical attribution that must not bypass the Enterprise tenancy boundary.
    DB::table('events')->where('id', $foreignEvent->id)->update(['organization_id' => $organization->id]);

    $this->actingAs($user)
        ->get(route('filament.admin.resources.events.index'))
        ->assertOk()
        ->assertSee('Visible enterprise activity')
        ->assertSee($enterprise->name)
        ->assertDontSee('Foreign enterprise activity must not leak');

    expect(EventResource::canCreate())->toBeFalse()
        ->and(EventResource::canEdit($visibleEvent))->toBeFalse()
        ->and(EventResource::canDelete($visibleEvent))->toBeFalse()
        ->and(EventResource::canDeleteAny())->toBeFalse()
        ->and(Route::has('filament.admin.resources.events.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.events.edit'))->toBeFalse();
});

it('lists authorized GitHub issue snapshots with external links and no mutation routes', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);

    $visibleIssue = Issue::factory()->create([
        'enterprise_id' => $enterprise->id,
        'repository' => 'cr8or/example',
        'number' => 42,
        'title' => 'Visible GitHub issue snapshot',
        'state' => 'open',
        'url' => 'https://github.com/cr8or/example/issues/42',
    ]);
    Issue::factory()->create([
        'enterprise_id' => $foreignEnterprise->id,
        'title' => 'Foreign GitHub issue must not leak',
    ]);

    $this->actingAs($user)
        ->get(route('filament.admin.resources.issues.index'))
        ->assertOk()
        ->assertSee('Visible GitHub issue snapshot')
        ->assertSee('cr8or/example')
        ->assertSee('https://github.com/cr8or/example/issues/42', false)
        ->assertDontSee('Foreign GitHub issue must not leak');

    expect(IssueResource::canCreate())->toBeFalse()
        ->and(IssueResource::canEdit($visibleIssue))->toBeFalse()
        ->and(IssueResource::canDelete($visibleIssue))->toBeFalse()
        ->and(IssueResource::canDeleteAny())->toBeFalse()
        ->and(Route::has('filament.admin.resources.issues.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.issues.edit'))->toBeFalse();
});

it('denies Event and Issue resource access to users without organization membership', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.resources.events.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('filament.admin.resources.issues.index'))
        ->assertForbidden();
});