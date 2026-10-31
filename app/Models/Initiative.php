<?php

namespace App\Models;

use Database\Factories\InitiativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $enterprise_id
 * @property int|null $strategy_id
 */
#[Fillable(['enterprise_id', 'strategy_id', 'name', 'description'])]
class Initiative extends Model
{
    /** @use HasFactory<InitiativeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $initiative): void {
            if ($initiative->strategy_id === null) {
                return;
            }

            $strategy = Strategy::query()->with('objective')->find($initiative->strategy_id);
            if ($strategy === null || (int) $strategy->objective->enterprise_id !== (int) $initiative->enterprise_id) {
                throw new LogicException('Initiative Strategy must belong to the same Enterprise.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
