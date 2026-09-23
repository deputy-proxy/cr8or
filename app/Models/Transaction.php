<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'financial_account_id', 'transaction_category_id', 'amount', 'transaction_date', 'description', 'reference'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction): void {
            $account = FinancialAccount::query()->find($transaction->financial_account_id);
            $category = TransactionCategory::query()->find($transaction->transaction_category_id);

            if ($account === null || $category === null) {
                throw new LogicException('Transaction account and category must exist.');
            }

            if ((int) $account->enterprise_id !== (int) $transaction->enterprise_id) {
                throw new LogicException('Transaction financial account must belong to its enterprise.');
            }

            if ((int) $category->enterprise_id !== (int) $transaction->enterprise_id) {
                throw new LogicException('Transaction category must belong to its enterprise.');
            }

            if ($transaction->exists) {
                foreach (['enterprise_id', 'financial_account_id', 'transaction_category_id'] as $field) {
                    if ($transaction->isDirty($field)) {
                        throw new LogicException("Transaction {$field} cannot be changed after creation.");
                    }
                }
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

    /** @return BelongsTo<TransactionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'transaction_date' => 'date',
        ];
    }
}
