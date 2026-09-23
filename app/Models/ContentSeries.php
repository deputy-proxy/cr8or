<?php

namespace App\Models;

use Database\Factories\ContentSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['campaign_id', 'name', 'description', 'status'])]
class ContentSeries extends Model
{
    /** @use HasFactory<ContentSeriesFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    private const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_ARCHIVED];

    protected static function booted(): void
    {
        static::saving(function (ContentSeries $series): void {
            if (! in_array($series->status, self::STATUSES, true)) {
                throw new LogicException("Invalid content series status [{$series->status}].");
            }
        });
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<ContentItem, $this> */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid content series status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
            self::STATUS_ACTIVE => [self::STATUS_COMPLETED, self::STATUS_ARCHIVED],
            self::STATUS_COMPLETED, self::STATUS_ARCHIVED => [],
            default => throw new LogicException('Content series has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Content series cannot transition from [%s] to [%s].', $this->status, $status));
        }

        $this->status = $status;

        return $this;
    }
}