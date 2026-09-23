<?php

namespace App\Models;

use Database\Factories\RevenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'financial_account_id', 'transaction_id', 'financial_period_id', 'amount', 'currency', 'revenue_date', 'source', 'reference', 'description'])]
class Revenue extends Model
{
    /** @use HasFactory<RevenueFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Revenue $revenue): void {
            $revenue->validateScope();

            if ($revenue->exists) {
                foreach (['enterprise_id', 'financial_account_id', 'transaction_id', 'financial_period_id'] as $field) {
                    if ($revenue->isDirty($field)) {
                        throw new LogicException("Revenue {$field} cannot be changed after creation.");
                    }
                }
            }

            if ($revenue->amount < 0) {
                throw new LogicException('Revenue amount cannot be negative.');
            }

            if (! preg_match('/^[A-Z]{3}$/', $revenue->currency)) {
                throw new LogicException('Revenue currency must be a three-letter uppercase code.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<FinancialPeriod, $this> */
    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'revenue_date' => 'date'];
    }

    private function validateScope(): void
    {
        if (! Enterprise::query()->whereKey($this->enterprise_id)->exists()) {
            return;
        }

        if ($this->financial_account_id !== null && FinancialAccount::query()->whereKey($this->financial_account_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Revenue financial account must belong to its enterprise.');
        }

        if ($this->financial_period_id !== null && FinancialPeriod::query()->whereKey($this->financial_period_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Revenue financial period must belong to its enterprise.');
        }

        if ($this->transaction_id !== null) {
            $transaction = Transaction::query()->find($this->transaction_id);

            if ($transaction === null || (int) $transaction->enterprise_id !== (int) $this->enterprise_id) {
                throw new LogicException('Revenue transaction must belong to its enterprise.');
            }

            if ($this->financial_account_id !== null && (int) $transaction->financial_account_id !== (int) $this->financial_account_id) {
                throw new LogicException('Revenue financial account must match its transaction.');
            }

            if ($this->financial_period_id !== null && (int) $transaction->financial_period_id !== (int) $this->financial_period_id) {
                throw new LogicException('Revenue financial period must match its transaction.');
            }
        }
    }
}
