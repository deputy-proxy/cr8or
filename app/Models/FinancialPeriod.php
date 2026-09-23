<?php

namespace App\Models;

use Database\Factories\FinancialPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['enterprise_id', 'name', 'period_start', 'period_end', 'status'])]
class FinancialPeriod extends Model
{
    /** @use HasFactory<FinancialPeriodFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    private const HISTORICAL_FIELDS = [
        'enterprise_id',
        'name',
        'period_start',
        'period_end',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinancialPeriod $period): void {
            if (! in_array($period->status, [self::STATUS_ACTIVE, self::STATUS_CLOSED], true)) {
                throw new LogicException("Invalid financial period status [{$period->status}].");
            }

            if (Carbon::parse((string) $period->period_end)->lt(Carbon::parse((string) $period->period_start))) {
                throw new LogicException('Financial period end date must be on or after its start date.');
            }

            if ($period->exists) {
                foreach (self::HISTORICAL_FIELDS as $field) {
                    $period->{$field} = $period->getRawOriginal($field);
                }
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Revenue, $this> */
    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }
}
