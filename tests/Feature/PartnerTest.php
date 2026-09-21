<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('creates a partner belonging to an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $partner = Partner::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Acme Partner',
        'email' => 'partner@example.com',
    ]);

    expect($partner->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->partners->contains($partner))->toBeTrue()
        ->and($partner->status)->toBe('active');
});

it('keeps partner ownership when unrelated attributes change', function () {
    $enterprise = Enterprise::factory()->create();
    $partner = Partner::factory()->create(['enterprise_id' => $enterprise]);

    $partner->update(['name' => 'Updated Partner', 'phone' => '+40 700 000 001']);

    expect($partner->refresh()->enterprise->is($enterprise))->toBeTrue();
});

it('rejects duplicate partner emails within an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    Partner::factory()->create(['enterprise_id' => $enterprise, 'email' => 'partner@example.com']);

    expect(fn () => Partner::factory()->create([
        'enterprise_id' => $enterprise,
        'email' => 'partner@example.com',
    ]))->toThrow(QueryException::class);
});

it('allows the same partner email in different enterprises', function () {
    $enterprises = Enterprise::factory()->count(2)->create();

    Partner::factory()->create(['enterprise_id' => $enterprises[0], 'email' => 'partner@example.com']);
    Partner::factory()->create(['enterprise_id' => $enterprises[1], 'email' => 'partner@example.com']);

    expect(Partner::query()->where('email', 'partner@example.com')->count())->toBe(2);
});

it('enforces partner authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $partner = Partner::factory()->create(['enterprise_id' => $enterprise]);
    $foreignPartner = Partner::factory()->create([
        'enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization]),
    ]);

    expect(Gate::forUser($owner)->allows('view', $partner))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $partner))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $partner))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $partner))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignPartner))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [Partner::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Partner::class, $enterprise]))->toBeFalse();
});

it('keeps the partner schema limited to phase 1 fields', function () {
    expect(Schema::getColumnListing('partners'))->toBe([
        'id', 'enterprise_id', 'name', 'email', 'phone', 'status', 'created_at', 'updated_at',
    ]);
});
