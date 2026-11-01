<?php

namespace App\Models;

use App\Enums\KpiMeasurementFrequency;
use Database\Factories\KpiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'name', 'definition', 'unit', 'target_value', 'status', 'measurement_frequency'])]
class Kpi extends Model
{
    /** @use HasFactory<KpiFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Kpi $kpi): void {
            if ($kpi->isDirty('current_value')) {
                throw new LogicException('KPI current_value can only be changed by recording a measurement.');
            }
        });

        static::creating(function (Kpi $kpi): void {
            if ($kpi->current_value !== null) {
                throw new LogicException('A KPI current value must be established by recording a measurement.');
            }
        });

        static::deleting(function (Kpi $kpi): void {
            if ($kpi->measurements()->exists()) {
                throw new LogicException('A KPI with measurement history cannot be deleted. Archive it instead.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    /** @return HasMany<KpiMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(KpiMeasurement::class);
    }

    public function synchronizeCurrentValueFromLatestMeasurement(): void
    {
        $value = $this->measurements()
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->value('value');

        $this->forceFill(['current_value' => $value === null ? null : (string) $value])->saveQuietly();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'current_value' => 'decimal:4',
            'measurement_frequency' => KpiMeasurementFrequency::class,
        ];
    }
}
