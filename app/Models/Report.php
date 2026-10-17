<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'enterprise_id',
    'report_type',
    'status',
    'period_start',
    'period_end',
    'generated_at',
    'methodology_version',
    'result_payload',
])]
class Report extends Model
{
    public const STATUS_COMPLETED = 'completed';

    protected static function booted(): void
    {
        static::saving(function (Report $report): void {
            if ($report->status !== self::STATUS_COMPLETED) {
                throw new LogicException('Reports can only be persisted as completed derived results.');
            }

            if (Enterprise::query()->find($report->enterprise_id) === null) {
                throw new LogicException('Report must belong to an enterprise.');
            }

            $resultPayload = $report->getAttribute('result_payload');
            if ($report->report_type === 'competitive_analysis' && (! is_array($resultPayload) || $resultPayload === [])) {
                throw new LogicException('Competitive-analysis reports require a structured result payload.');
            }

            if ($report->exists && $report->isDirty(['enterprise_id', 'report_type', 'period_start', 'period_end', 'generated_at', 'methodology_version', 'result_payload'])) {
                throw new LogicException('Report identity is immutable; create a new report instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'generated_at' => 'datetime',
            'result_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasOne<ReportSnapshot, $this> */
    public function snapshot(): HasOne
    {
        return $this->hasOne(ReportSnapshot::class);
    }

    /** @return HasMany<ReportMetricValue, $this> */
    public function metricValues(): HasMany
    {
        return $this->hasMany(ReportMetricValue::class);
    }
}