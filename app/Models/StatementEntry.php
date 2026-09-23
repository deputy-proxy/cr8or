<?php

namespace App\Models;

use Database\Factories\StatementEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'statement_id', 'financial_account_id', 'transaction_id', 'source', 'source_reference', 'amount', 'entry_date', 'description', 'reference', 'metadata'])]
class StatementEntry extends Model
{
    /** @use HasFactory<StatementEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'entry_date' => 'date',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StatementEntry $entry): void {
            $statement = Statement::query()->find($entry->statement_id);
            $account = FinancialAccount::query()->find($entry->financial_account_id);

            if ($statement === null || $account === null) {
                throw new LogicException('Statement entry statement and financial account must exist.');
            }

            if ((int) $statement->organization_id !== (int) $entry->organization_id
                || (int) $statement->enterprise_id !== (int) $entry->enterprise_id
                || (int) $statement->financial_account_id !== (int) $entry->financial_account_id) {
                throw new LogicException('Statement entry scope must match its statement and financial account.');
            }

            if ($entry->transaction_id !== null) {
                $transaction = Transaction::query()->find($entry->transaction_id);

                if ($transaction === null
                    || (int) $transaction->enterprise_id !== (int) $entry->enterprise_id
                    || (int) $transaction->financial_account_id !== (int) $entry->financial_account_id) {
                    throw new LogicException('Statement entry transaction must belong to the same enterprise and financial account.');
                }
            }

            if ($entry->exists) {
                foreach (['organization_id', 'enterprise_id', 'statement_id', 'financial_account_id', 'source', 'source_reference'] as $field) {
                    if ($entry->isDirty($field)) {
                        throw new LogicException("Statement entry {$field} cannot be changed after creation.");
                    }
                }
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Statement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
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
}
