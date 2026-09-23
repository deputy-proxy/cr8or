<?php

namespace App\Models;

use Database\Factories\FinancialReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enterprise_id',
    'financial_period_id',
    'financial_account_id',
    'transaction_category_id',
    'currency',
    'metrics',
    'source_snapshot',
    'generated_at',
])]
class FinancialReport extends Model
{
    /** @use HasFactory<FinancialReportFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<FinancialPeriod, $this> */
    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return BelongsTo<TransactionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'source_snapshot' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
