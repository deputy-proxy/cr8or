<?php

use App\Models\Enterprise;
use App\Models\Kpi;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('stores a KPI definition and current recorded value without calculation behavior', function () {
    $enterprise = Enterprise::factory()->create();
    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Monthly recurring revenue',
        'definition' => 'Total recurring subscription revenue recorded for the month.',
        'unit' => 'currency',
        'target_value' => 10000,
        'current_value' => 7500,
    ]);

    $kpi->refresh();

    expect($kpi->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->kpis->contains($kpi))->toBeTrue()
        ->and($kpi->target_value)->toBe('10000.0000')
        ->and($kpi->current_value)->toBe('7500.0000');
});

it('keeps KPI ownership and recorded values when unrelated attributes change', function () {
    $enterprise = Enterprise::factory()->create();
    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise,
        'target_value' => 100,
        'current_value' => 75,
    ]);

    $kpi->update(['name' => 'Updated KPI']);

    expect($kpi->refresh()->enterprise->is($enterprise))->toBeTrue()
        ->and($kpi->current_value)->toBe('75.0000');
});

it('enforces KPI authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);
    $foreignKpi = Kpi::factory()->create([
        'enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization]),
    ]);

    expect(Gate::forUser($owner)->allows('view', $kpi))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $kpi))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $kpi))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $kpi))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignKpi))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Kpi::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Kpi::class, $enterprise]))->toBeFalse();
});

it('keeps the KPI schema limited to phase 1 definition and recorded value fields', function () {
    expect(Schema::getColumnListing('kpis'))->toBe([
        'id', 'enterprise_id', 'name', 'definition', 'unit', 'target_value', 'current_value', 'status', 'created_at', 'updated_at',
    ]);
});
