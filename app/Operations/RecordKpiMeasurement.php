<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordKpiMeasurement implements Operation
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): KpiMeasurement
    {
        $input['kpi_id'] ??= ($input['kpi'] ?? null) instanceof Kpi
            ? $input['kpi']->getKey()
            : null;
        $input['source'] ??= KpiMeasurement::SOURCE_MANUAL;

        $validated = Validator::make($input, [
            'kpi_id' => ['required', 'integer', 'exists:kpis,id'],
            'value' => ['required', 'numeric', 'decimal:0,4'],
            'measured_at' => ['required', 'date'],
            'source' => ['required', 'in:manual,integration'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated): KpiMeasurement {
            /** @var Kpi $kpi */
            $kpi = Kpi::query()
                ->whereKey((int) $validated['kpi_id'])
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $kpi);

            if ($kpi->status !== 'active') {
                throw ValidationException::withMessages([
                    'kpi_id' => 'Measurements can only be recorded for active KPIs.',
                ]);
            }

            $measurement = $kpi->measurements()->create([
                'value' => $validated['value'],
                'measured_at' => Carbon::parse($validated['measured_at']),
                'source' => $validated['source'],
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $actor->getKey(),
            ]);

            $kpi->synchronizeCurrentValueFromLatestMeasurement();

            return $measurement->load(['kpi', 'recordedBy']);
        });
    }
}
