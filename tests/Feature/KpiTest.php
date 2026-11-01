<?php

use App\Enums\KpiMeasurementFrequency;
use App\Models\Enterprise;
use App\Models\Kpi;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('stores a KPI definition without inventing a current measurement', function () {
    $enterprise = Enterprise::factory()->create();
    $kpi = Kpi::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Monthly recurring revenue',
        'definition' => 'Total recurring subscription revenue recorded for the month.',
        'unit' => 'currency',
        'target_value' => 10000,
        'measurement_frequency' => 'monthly',
    ]);

    $kpi->refresh();

    expect($kpi->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->kpis->contains($kpi))->toBeTrue()
        ->and($kpi->target_value)->toBe('10000.0000')
        ->and($kpi->measurement_frequency)->toBe(KpiMeasurementFrequency::Monthly)
        ->and($kpi->current_value)->toBeNull()
        ->and($kpi->measurements)->toBeEmpty();
});

it('keeps KPI ownership and cached value when unrelated attributes change', function () {
    $enterprise = Enterprise::factory()->create();
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);

    $kpi->update(['name' => 'Updated KPI']);

    expect($kpi->refresh()->enterprise->is($enterprise))->toBeTrue()
        ->and($kpi->current_value)->toBeNull();
});

it('does not allow current value to be changed outside measurement recording', function () {
    $kpi = Kpi::factory()->create();

    $kpi->current_value = 75;

    expect(fn () => $kpi->save())
        ->toThrow(LogicException::class, 'KPI current_value can only be changed by recording a measurement.');
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

it('adds measurement history and an optional frequency to the KPI schema', function () {
    expect(Schema::getColumnListing('kpis'))->toBe([
        'id', 'enterprise_id', 'name', 'definition', 'unit', 'target_value', 'current_value', 'status', 'created_at', 'updated_at', 'measurement_frequency',
    ])->and(Schema::hasTable('kpi_measurements'))->toBeTrue();
});
