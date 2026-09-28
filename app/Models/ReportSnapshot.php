<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'report_id',
    'captured_at',
    'period_start',
    'period_end',
    'methodology_version',
    'source_records',
    'source_fingerprint',
])]
class ReportSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'source_records' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ReportSnapshot $snapshot): void {
            if ($snapshot->exists) {
                throw new LogicException('Report snapshots are immutable.');
            }
        });
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}