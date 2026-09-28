<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'key', 'name', 'description', 'unit', 'methodology', 'status'])]
class MetricDefinition extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected static function booted(): void
    {
        static::saving(function (MetricDefinition $metric): void {
            if (! in_array($metric->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid metric definition status [{$metric->status}].");
            }

            if (Enterprise::query()->find($metric->enterprise_id) === null) {
                throw new LogicException('Metric definition must belong to an enterprise.');
            }

            if ($metric->exists && $metric->isDirty('enterprise_id')) {
                throw new LogicException('Metric definition enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<ReportMetricValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ReportMetricValue::class);
    }
}