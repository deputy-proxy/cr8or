<?php

namespace App\Models;

use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'name', 'type', 'status', 'currency'])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected static function booted(): void
    {
        static::saving(function (FinancialAccount $account): void {
            if (! in_array($account->status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
                throw new LogicException("Invalid financial account status [{$account->status}].");
            }

            if (! preg_match('/^[A-Z]{3}$/', $account->currency)) {
                throw new LogicException('Financial account currency must be a three-letter uppercase code.');
            }

            if ($account->exists && $account->isDirty('enterprise_id')) {
                throw new LogicException('Financial account enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Statement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class);
    }

    /** @return HasMany<StatementEntry, $this> */
    public function statementEntries(): HasMany
    {
        return $this->hasMany(StatementEntry::class);
    }
}