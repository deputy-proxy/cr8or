<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['report_id', 'metric_definition_id', 'value', 'unit', 'calculation', 'source_records'])]
class ReportMetricValue extends Model
{
    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'source_records' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (ReportMetricValue $value): void {
            if ($value->exists && $value->isDirty(['report_id', 'metric_definition_id', 'value', 'unit', 'calculation', 'source_records'])) {
                throw new LogicException('Report metric values are immutable.');
            }
        });
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<MetricDefinition, $this> */
    public function metricDefinition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class);
    }
}