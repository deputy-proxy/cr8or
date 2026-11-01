<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['kpi_id', 'value', 'measured_at', 'source', 'notes', 'recorded_by'])]
class KpiMeasurement extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_INTEGRATION = 'integration';

    public const SOURCE_LEGACY_MIGRATION = 'legacy_migration';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('KPI measurements are immutable. Record a correction as a new measurement.');
        });

        static::deleting(function (): never {
            throw new LogicException('KPI measurements cannot be deleted individually.');
        });
    }

    /** @return BelongsTo<Kpi, $this> */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'measured_at' => 'datetime',
        ];
    }
}
