<?php

namespace App\Models;

use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'campaign_id', 'content_series_id', 'channel_id', 'audience_id', 'agent_execution_id', 'agent_decision_id', 'title', 'body', 'status'])]
class ContentItem extends Model
{
    /** @use HasFactory<ContentItemFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLICATION_READY = 'publication_ready';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    private const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_PUBLICATION_READY,
        self::STATUS_ARCHIVED,
    ];

    protected static function booted(): void
    {
        static::saving(function (ContentItem $item): void {
            if (! in_array($item->status, self::STATUSES, true)) {
                throw new LogicException("Invalid content item status [{$item->status}].");
            }

            $campaign = Campaign::query()->find($item->campaign_id);
            if ($campaign === null || $campaign->enterprise_id !== (int) $item->enterprise_id) {
                throw new LogicException('Content item campaign must belong to its enterprise.');
            }

            if ($item->content_series_id !== null) {
                $series = ContentSeries::query()->find($item->content_series_id);
                if ($series === null || $series->campaign_id !== $campaign->id) {
                    throw new LogicException('Content item series must belong to its campaign.');
                }
            }

            foreach (['channel_id' => Channel::class, 'audience_id' => Audience::class] as $field => $model) {
                $id = $item->{$field};
                if ($id === null) {
                    continue;
                }

                $record = $model::query()->find($id);
                if ($record === null || $record->enterprise_id !== (int) $item->enterprise_id) {
                    throw new LogicException("Content item {$field} must belong to its enterprise.");
                }
            }

            if ($item->exists && $item->isDirty('enterprise_id')) {
                throw new LogicException('Content item enterprise ownership cannot be changed.');
            }

            if ($item->exists && in_array($item->getOriginal('status'), [self::STATUS_APPROVED, self::STATUS_PUBLICATION_READY], true)) {
                foreach (['campaign_id', 'content_series_id', 'channel_id', 'audience_id', 'title', 'body'] as $field) {
                    if ($item->isDirty($field)) {
                        throw new LogicException("Approved content item {$field} cannot be silently changed.");
                    }
                }
            }

            if ($item->exists && $item->isDirty('agent_execution_id') && $item->getOriginal('agent_execution_id') !== null) {
                throw new LogicException('Content item Agent execution provenance cannot be replaced.');
            }

            if ($item->exists && $item->isDirty('agent_decision_id') && $item->getOriginal('agent_decision_id') !== null) {
                throw new LogicException('Content item Agent decision provenance cannot be replaced.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<ContentSeries, $this> */
    public function contentSeries(): BelongsTo
    {
        return $this->belongsTo(ContentSeries::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<Audience, $this> */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /** @return BelongsTo<AgentDecision, $this> */
    public function agentDecision(): BelongsTo
    {
        return $this->belongsTo(AgentDecision::class);
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @return HasMany<Script, $this> */
    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
    }

    /** @return HasMany<Publication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function transitionTo(string $status): static
    {
        if ($status === self::STATUS_PUBLICATION_READY) {
            throw new LogicException('Publication readiness requires a matching server-side approval.');
        }

        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid content item status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_IN_REVIEW, self::STATUS_ARCHIVED],
            self::STATUS_IN_REVIEW => [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_ARCHIVED],
            self::STATUS_APPROVED => [self::STATUS_ARCHIVED],
            self::STATUS_PUBLICATION_READY => [self::STATUS_ARCHIVED],
            self::STATUS_ARCHIVED => [],
            default => throw new LogicException('Content item has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf('Content item cannot transition from [%s] to [%s].', $this->status, $status));
        }

        $this->status = $status;

        return $this;
    }

    public function transitionToPublicationReady(): static
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new LogicException('Only approved content can become publication-ready.');
        }

        $this->status = self::STATUS_PUBLICATION_READY;

        return $this;
    }
}