<?php

namespace App\Models;

use Database\Factories\RenderRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'content_item_id', 'asset_id', 'source_version_id', 'type', 'parameters', 'status', 'idempotency_key', 'correlation_id', 'external_request_id', 'failure_code', 'failure_reason'])]
class RenderRequest extends Model
{
    /** @use HasFactory<RenderRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (RenderRequest $r): void {
            if (! in_array($r->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
                throw new LogicException("Invalid render request status [{$r->status}].");
            }if ($r->content_item_id !== null) {
                $i = ContentItem::query()->find($r->content_item_id);
                if ($i === null || (int) $i->enterprise_id !== (int) $r->enterprise_id) {
                    throw new LogicException('Render content item must belong to its enterprise.');
                }
            }if ($r->asset_id !== null) {
                $a = Asset::query()->find($r->asset_id);
                if ($a === null || (int) $a->enterprise_id !== (int) $r->enterprise_id) {
                    throw new LogicException('Render asset must belong to its enterprise.');
                }
            }if ($r->source_version_id !== null) {
                $v = AssetVersion::query()->with('asset')->find($r->source_version_id);
                if ($v === null || (int) $v->asset->enterprise_id !== (int) $r->enterprise_id) {
                    throw new LogicException('Render source version must belong to its enterprise.');
                }
            }if ($r->status === self::STATUS_SUCCEEDED && $r->failure_reason !== null) {
                throw new LogicException('A succeeded render request cannot have a failure reason.');
            }if ($r->status === self::STATUS_FAILED && $r->failure_reason === null) {
                throw new LogicException('A failed render request must have a failure reason.');
            }if ($r->exists && $r->getRawOriginal('status') === self::STATUS_FAILED && $r->status === self::STATUS_SUCCEEDED) {
                throw new LogicException('A failed render request cannot be represented as succeeded.');
            }
        });
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'source_version_id');
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<RenderJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],self::STATUS_SUCCEEDED,self::STATUS_FAILED => [],default => throw new LogicException('Render request has no valid lifecycle state.')
        };
        if (! in_array($status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true) || ! in_array($status, $allowed, true)) {
            throw new LogicException("Render request cannot transition from [{$this->status}] to [{$status}].");
        }$this->status = $status;

        return $this;
    }

    public function fail(string $reason, ?string $code = null): static
    {
        $this->failure_reason = $reason;
        $this->failure_code = $code;

        return $this->transitionTo(self::STATUS_FAILED);
    }
}
