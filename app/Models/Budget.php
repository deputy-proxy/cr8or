<?php

namespace App\Models;

use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'enterprise_id',
    'financial_period_id',
    'financial_account_id',
    'transaction_category_id',
    'name',
    'planned_amount',
    'currency',
    'description',
])]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'enterprise_id',
        'financial_period_id',
        'financial_account_id',
        'transaction_category_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Budget $budget): void {
            $budget->validateScope();

            if (! preg_match('/^[A-Z]{3}$/', $budget->currency)) {
                throw new LogicException('Budget currency must be a three-letter uppercase code.');
            }

            if ($budget->planned_amount < 0) {
                throw new LogicException('Budget planned amount cannot be negative.');
            }

            if ($budget->exists) {
                foreach (self::HISTORICAL_FIELDS as $field) {
                    $budget->{$field} = $budget->getRawOriginal($field);
                }
            }
        });
    }

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
        return ['planned_amount' => 'decimal:4'];
    }

    private function validateScope(): void
    {
        if (! Enterprise::query()->whereKey($this->enterprise_id)->exists()) {
            return;
        }

        $periodEnterpriseId = FinancialPeriod::query()->whereKey($this->financial_period_id)->value('enterprise_id');

        if ($periodEnterpriseId === null || (int) $periodEnterpriseId !== (int) $this->enterprise_id) {
            throw new LogicException('Budget financial period must belong to its enterprise.');
        }

        if ($this->financial_account_id !== null) {
            $accountEnterpriseId = FinancialAccount::query()->whereKey($this->financial_account_id)->value('enterprise_id');

            if ($accountEnterpriseId === null || (int) $accountEnterpriseId !== (int) $this->enterprise_id) {
                throw new LogicException('Budget financial account must belong to its enterprise.');
            }
        }

        if ($this->transaction_category_id !== null) {
            $categoryEnterpriseId = TransactionCategory::query()->whereKey($this->transaction_category_id)->value('enterprise_id');

            if ($categoryEnterpriseId === null || (int) $categoryEnterpriseId !== (int) $this->enterprise_id) {
                throw new LogicException('Budget category must belong to its enterprise.');
            }
        }
    }
}
