<?php

use App\Filament\Resources\Kpis\Pages\EditKpi;
use App\Models\Enterprise;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Operations\RecordKpiMeasurement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function kpiMeasurementActorFor(Enterprise $enterprise): User
{
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    return $user;
}

it('records append-only measurements and synchronizes the latest current value', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);

    $operation = app(RecordKpiMeasurement::class);
    $first = $operation->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 1250.25,
        'measured_at' => '2026-01-01 09:00:00',
        'notes' => 'Initial month close',
    ]);
    $second = $operation->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 1499.75,
        'measured_at' => '2026-02-01 09:00:00',
        'source' => KpiMeasurement::SOURCE_MANUAL,
    ]);

    expect($kpi->refresh()->current_value)->toBe('1499.7500')
        ->and($kpi->measurements()->count())->toBe(2)
        ->and($first->value)->toBe('1250.2500')
        ->and($first->source)->toBe(KpiMeasurement::SOURCE_MANUAL)
        ->and($first->recorded_by)->toBe($actor->getKey())
        ->and($second->value)->toBe('1499.7500');
});

it('does not let a backdated measurement replace a newer current value', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);
    $operation = app(RecordKpiMeasurement::class);

    $operation->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 200,
        'measured_at' => '2026-05-01 12:00:00',
    ]);
    $operation->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 150,
        'measured_at' => '2026-04-01 12:00:00',
    ]);

    expect($kpi->refresh()->current_value)->toBe('200.0000')
        ->and($kpi->measurements()->orderBy('measured_at')->value('value'))->toBe('150.0000');
});

it('uses the measurement ID as a deterministic tie breaker for equal timestamps', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);
    $operation = app(RecordKpiMeasurement::class);
    $timestamp = '2026-06-01 12:00:00';

    $operation->execute($actor, ['kpi_id' => $kpi->getKey(), 'value' => 10, 'measured_at' => $timestamp]);
    $latest = $operation->execute($actor, ['kpi_id' => $kpi->getKey(), 'value' => 20, 'measured_at' => $timestamp]);

    expect($kpi->refresh()->current_value)->toBe('20.0000')
        ->and($kpi->measurements()->orderByDesc('measured_at')->orderByDesc('id')->value('id'))->toBe($latest->getKey());
});

it('rejects unauthorized and cross-organization measurement attempts', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $member = User::factory()->create();
    $foreignOwner = kpiMeasurementActorFor($foreignEnterprise);

    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);

    expect(fn () => app(RecordKpiMeasurement::class)->execute($member, [
        'kpi_id' => $kpi->getKey(),
        'value' => 10,
        'measured_at' => now(),
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => app(RecordKpiMeasurement::class)->execute($foreignOwner, [
        'kpi_id' => $kpi->getKey(),
        'value' => 10,
        'measured_at' => now(),
    ]))->toThrow(AuthorizationException::class);

    expect($kpi->measurements()->count())->toBe(0);
});

it('rejects measurements for archived KPIs and invalid sources', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->archived()->create(['enterprise_id' => $enterprise]);

    expect(fn () => app(RecordKpiMeasurement::class)->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 10,
        'measured_at' => now(),
    ]))->toThrow(ValidationException::class);

    $activeKpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);
    expect(fn () => app(RecordKpiMeasurement::class)->execute($actor, [
        'kpi_id' => $activeKpi->getKey(),
        'value' => 10.12345,
        'measured_at' => now(),
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(RecordKpiMeasurement::class)->execute($actor, [
        'kpi_id' => $activeKpi->getKey(),
        'value' => 10,
        'measured_at' => now(),
        'source' => KpiMeasurement::SOURCE_LEGACY_MIGRATION,
    ]))->toThrow(ValidationException::class);
});

it('does not allow persisted measurements to be overwritten or individually deleted', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);
    $measurement = app(RecordKpiMeasurement::class)->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 10,
        'measured_at' => now(),
    ]);

    expect(fn () => $measurement->update(['value' => 99]))
        ->toThrow(LogicException::class, 'KPI measurements are immutable.');

    expect(fn () => $measurement->delete())
        ->toThrow(LogicException::class, 'KPI measurements cannot be deleted individually.');
});

it('backfills pre-existing current values as legacy baseline measurements', function () {
    $kpiWithValue = Kpi::factory()->create();
    $kpiWithoutValue = Kpi::factory()->create();

    DB::table('kpis')->where('id', $kpiWithValue->getKey())->update(['current_value' => '321.4500']);

    $migration = require database_path('migrations/2026_11_01_000001_create_kpi_measurements_table.php');
    $migration->down();
    $migration->up();

    $baseline = KpiMeasurement::query()->where('kpi_id', $kpiWithValue->getKey())->sole();

    expect($baseline->value)->toBe('321.4500')
        ->and($baseline->source)->toBe(KpiMeasurement::SOURCE_LEGACY_MIGRATION)
        ->and($baseline->measured_at->toDateTimeString())->toBe($kpiWithValue->updated_at->toDateTimeString())
        ->and(KpiMeasurement::query()->where('kpi_id', $kpiWithoutValue->getKey())->exists())->toBeFalse();
});

it('rolls back a measurement, its current-value cache update, and its activity event together', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);

    expect(fn () => DB::transaction(function () use ($actor, $kpi): void {
        app(RecordKpiMeasurement::class)->execute($actor, [
            'kpi_id' => $kpi->getKey(),
            'value' => 88,
            'measured_at' => '2026-07-01 12:00:00',
        ]);

        throw new RuntimeException('Force the surrounding transaction to roll back.');
    }))->toThrow(RuntimeException::class);

    expect($kpi->refresh()->current_value)->toBeNull()
        ->and($kpi->measurements()->count())->toBe(0);
});

it('authorizes measurement reads through the parent KPI and denies standalone mutations', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $foreignActor = kpiMeasurementActorFor($foreignEnterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise]);
    $measurement = app(RecordKpiMeasurement::class)->execute($actor, [
        'kpi_id' => $kpi->getKey(),
        'value' => 10,
        'measured_at' => now(),
    ]);

    expect(Gate::forUser($actor)->allows('delete', $kpi))->toBeFalse()
        ->and(fn () => $kpi->delete())->toThrow(LogicException::class, 'A KPI with measurement history cannot be deleted. Archive it instead.');

    expect(Gate::forUser($actor)->allows('viewAny', KpiMeasurement::class))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('view', $measurement))->toBeTrue()
        ->and(Gate::forUser($foreignActor)->allows('view', $measurement))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('create', KpiMeasurement::class))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('update', $measurement))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('delete', $measurement))->toBeFalse();
});

it('renders the KPI chart and history and records measurements through the Filament action', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = kpiMeasurementActorFor($enterprise);
    $kpi = Kpi::factory()->create(['enterprise_id' => $enterprise, 'unit' => 'EUR']);
    $operation = app(RecordKpiMeasurement::class);
    $operation->execute($actor, ['kpi_id' => $kpi->getKey(), 'value' => 10, 'measured_at' => '2026-01-01']);
    $operation->execute($actor, ['kpi_id' => $kpi->getKey(), 'value' => 15, 'measured_at' => '2026-02-01']);

    $this->actingAs($actor);

    $component = Livewire::test(EditKpi::class, ['record' => $kpi->getRouteKey()]);
    expect($component->instance()->getRelationManagers())->toContain(\App\Filament\Resources\Kpis\RelationManagers\KpiMeasurementsRelationManager::class);

    $component
        ->assertStatus(200)
        ->assertSee('Historical evolution')
        ->assertSee('2 measurements')
        ->assertActionExists('recordMeasurement')
        ->callAction('recordMeasurement', [
            'value' => 20,
            'measured_at' => '2026-03-01 12:00:00',
            'notes' => 'March close',
        ])
        ->assertHasNoActionErrors();

    expect($kpi->refresh()->current_value)->toBe('20.0000')
        ->and($kpi->measurements()->count())->toBe(3)
        ->and($kpi->measurements()->latest('id')->value('notes'))->toBe('March close');
});
