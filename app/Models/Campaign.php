<?php

namespace App\Models;

use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'marketing_strategy_id', 'name', 'description', 'status'])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    private const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_COMPLETED, self::STATUS_ARCHIVED];

    protected static function booted(): void
    {
        static::saving(function (Campaign $campaign): void {
            if (! in_array($campaign->status, self::STATUSES, true)) {
                throw new LogicException("Invalid campaign status [{$campaign->status}].");
            }

            $strategy = MarketingStrategy::query()->find($campaign->marketing_strategy_id);
            if ($strategy === null || $strategy->enterprise_id !== (int) $campaign->enterprise_id) {
                throw new LogicException('Campaign strategy must belong to its enterprise.');
            }

            if ($campaign->exists && $campaign->isDirty('enterprise_id')) {
                throw new LogicException('Campaign enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<MarketingStrategy, $this> */
    public function marketingStrategy(): BelongsTo
    {
        return $this->belongsTo(MarketingStrategy::class);
    }

    /** @return HasMany<ContentSeries, $this> */
    public function contentSeries(): HasMany
    {
        return $this->hasMany(ContentSeries::class);
    }

    /** @return HasMany<ContentItem, $this> */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid campaign status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
            self::STATUS_ACTIVE => [self::STATUS_PAUSED, self::STATUS_COMPLETED, self::STATUS_ARCHIVED],
            self::STATUS_PAUSED => [self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_ARCHIVED],
            self::STATUS_COMPLETED, self::STATUS_ARCHIVED => [],
            default => throw new LogicException('Campaign has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Campaign cannot transition from [%s] to [%s].', $this->status, $status));
        }

        $this->status = $status;

        return $this;
    }
}
