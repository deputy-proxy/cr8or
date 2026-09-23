<?php

namespace App\Models;

use Database\Factories\BusinessHealthResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enterprise_id',
    'financial_report_id',
    'health_status',
    'metrics',
    'source_snapshot',
    'evaluated_at',
])]
class BusinessHealthResult extends Model
{
    /** @use HasFactory<BusinessHealthResultFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<FinancialReport, $this> */
    public function financialReport(): BelongsTo
    {
        return $this->belongsTo(FinancialReport::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'source_snapshot' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }
}
