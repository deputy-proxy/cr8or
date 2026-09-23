<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'financial_account_id', 'transaction_id', 'transaction_category_id', 'financial_period_id', 'amount', 'currency', 'expense_date', 'source', 'reference', 'description'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Expense $expense): void {
            $expense->validateScope();

            if ($expense->exists) {
                foreach (['enterprise_id', 'financial_account_id', 'transaction_id', 'transaction_category_id', 'financial_period_id'] as $field) {
                    if ($expense->isDirty($field)) {
                        throw new LogicException("Expense {$field} cannot be changed after creation.");
                    }
                }
            }

            if ($expense->amount < 0) {
                throw new LogicException('Expense amount cannot be negative.');
            }

            if (! preg_match('/^[A-Z]{3}$/', $expense->currency)) {
                throw new LogicException('Expense currency must be a three-letter uppercase code.');
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

    /** @return BelongsTo<TransactionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /** @return BelongsTo<FinancialPeriod, $this> */
    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'expense_date' => 'date'];
    }

    private function validateScope(): void
    {
        if (! Enterprise::query()->whereKey($this->enterprise_id)->exists()) {
            return;
        }

        if ($this->financial_account_id !== null && FinancialAccount::query()->whereKey($this->financial_account_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Expense financial account must belong to its enterprise.');
        }

        if ($this->financial_period_id !== null && FinancialPeriod::query()->whereKey($this->financial_period_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Expense financial period must belong to its enterprise.');
        }

        if ($this->transaction_id !== null) {
            $transaction = Transaction::query()->find($this->transaction_id);

            if ($transaction === null || (int) $transaction->enterprise_id !== (int) $this->enterprise_id) {
                throw new LogicException('Expense transaction must belong to its enterprise.');
            }

            if ($this->financial_account_id !== null && (int) $transaction->financial_account_id !== (int) $this->financial_account_id) {
                throw new LogicException('Expense financial account must match its transaction.');
            }

            if ($this->transaction_category_id !== null && (int) $transaction->transaction_category_id !== (int) $this->transaction_category_id) {
                throw new LogicException('Expense category must match its transaction.');
            }

            if ($this->financial_period_id !== null && (int) $transaction->financial_period_id !== (int) $this->financial_period_id) {
                throw new LogicException('Expense financial period must match its transaction.');
            }
        }

        if ($this->transaction_category_id !== null && TransactionCategory::query()->whereKey($this->transaction_category_id)->value('enterprise_id') !== $this->enterprise_id) {
            throw new LogicException('Expense category must belong to its enterprise.');
        }
    }
}
