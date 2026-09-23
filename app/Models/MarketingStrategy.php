<?php

namespace App\Models;

use Database\Factories\MarketingStrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'name', 'description', 'status'])]
class MarketingStrategy extends Model
{
    /** @use HasFactory<MarketingStrategyFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    private const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    protected static function booted(): void
    {
        static::saving(function (MarketingStrategy $strategy): void {
            if (! in_array($strategy->status, self::STATUSES, true)) {
                throw new LogicException("Invalid marketing strategy status [{$strategy->status}].");
            }

            if ($strategy->exists && $strategy->isDirty('enterprise_id')) {
                throw new LogicException('Marketing strategy enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid marketing strategy status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
            self::STATUS_ACTIVE => [self::STATUS_ARCHIVED],
            self::STATUS_ARCHIVED => [],
            default => throw new LogicException('Marketing strategy has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Marketing strategy cannot transition from [%s] to [%s].', $this->status, $status));
        }

        $this->status = $status;

        return $this;
    }
}
