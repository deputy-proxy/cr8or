<?php

namespace App\Models;

use Database\Factories\StatementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'financial_account_id', 'source', 'source_reference', 'statement_date', 'period_start', 'period_end', 'metadata'])]
class Statement extends Model
{
    /** @use HasFactory<StatementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Statement $statement): void {
            $enterprise = Enterprise::query()->find($statement->enterprise_id);
            $account = FinancialAccount::query()->find($statement->financial_account_id);

            if ($enterprise === null || $account === null) {
                throw new LogicException('Statement enterprise and financial account must exist.');
            }

            if ((int) $account->enterprise_id !== (int) $statement->enterprise_id) {
                throw new LogicException('Statement financial account must belong to its enterprise.');
            }

            if ((int) $enterprise->organization_id !== (int) $statement->organization_id) {
                throw new LogicException('Statement enterprise must belong to its organization.');
            }

            if ($statement->period_start !== null && $statement->period_end !== null
                && Carbon::parse((string) $statement->period_start)->greaterThan(Carbon::parse((string) $statement->period_end))) {
                throw new LogicException('Statement period start cannot be after its end.');
            }

            if ($statement->exists) {
                foreach (['organization_id', 'enterprise_id', 'financial_account_id', 'source', 'source_reference'] as $field) {
                    if ($statement->isDirty($field)) {
                        throw new LogicException("Statement {$field} cannot be changed after creation.");
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

    /** @return BelongsTo<FinancialAccount, $this> */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /** @return HasMany<StatementEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(StatementEntry::class);
    }
}
