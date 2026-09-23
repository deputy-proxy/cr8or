<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable(['enterprise_id', 'content_item_id', 'channel_id', 'social_account_id', 'approval_request_id', 'status', 'idempotency_key', 'correlation_id', 'external_id', 'external_url', 'scheduled_at', 'submitted_at', 'published_at', 'failure_code', 'failure_reason'])]
/** @property-read Enterprise $enterprise */
class Publication extends Model
{
    /** @use HasFactory<\Database\Factories\PublicationFactory> */
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $casts = ['scheduled_at' => 'datetime', 'submitted_at' => 'datetime', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $p): void {
            if (! in_array($p->status, [self::STATUS_SCHEDULED, self::STATUS_SUBMITTED, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
                throw new LogicException("Invalid publication status [{$p->status}].");
            }
            $c = ContentItem::query()->find($p->content_item_id);
            $ch = Channel::query()->find($p->channel_id);
            $a = SocialAccount::query()->find($p->social_account_id);
            if ($c === null || (int) $c->enterprise_id !== (int) $p->enterprise_id) {
                throw new LogicException('Publication content must belong to its enterprise.');
            }
            if ($ch === null || (int) $ch->enterprise_id !== (int) $p->enterprise_id) {
                throw new LogicException('Publication channel must belong to its enterprise.');
            }
            if ($a === null || (int) $a->enterprise_id !== (int) $p->enterprise_id || (int) $a->channel_id !== (int) $p->channel_id) {
                throw new LogicException('Publication social account must belong to its enterprise and channel.');
            }
            if ($p->exists) {
                foreach (['enterprise_id', 'content_item_id', 'channel_id', 'social_account_id', 'idempotency_key'] as $f) {
                    if ($p->isDirty($f)) {
                        throw new LogicException("Publication {$f} is historical and cannot be changed.");
                    }
                }
            }
        });
    }

    /** @return BelongsTo<ContentItem, $this> */
    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** @return HasOne<PublicationSchedule, $this> */
    public function schedule(): HasOne
    {
        return $this->hasOne(PublicationSchedule::class);
    }

    /** @return HasMany<PublishingJob, $this> */
    public function publishingJobs(): HasMany
    {
        return $this->hasMany(PublishingJob::class);
    }

    /** @return HasMany<PublicationResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(PublicationResult::class);
    }

    public function markSubmitted(string $id, ?string $url = null): static
    {
        if (! in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_FAILED], true)) {
            throw new LogicException('Publication cannot be submitted from its current state.');
        }
        $this->status = self::STATUS_SUBMITTED;
        $this->external_id = $id;
        $this->external_url = $url;
        $this->submitted_at ??= CarbonImmutable::now();

        return $this;
    }

    public function succeed(): static
    {
        if ($this->status !== self::STATUS_SUBMITTED) {
            throw new LogicException('Publication can only succeed from submitted state.');
        }
        $this->status = self::STATUS_SUCCEEDED;
        $this->published_at ??= CarbonImmutable::now();

        return $this;
    }

    public function fail(?string $code, ?string $reason): static
    {
        $this->status = self::STATUS_FAILED;
        $this->failure_code = $code;
        $this->failure_reason = $reason;

        return $this;
    }
}