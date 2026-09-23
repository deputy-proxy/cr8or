<?php

namespace App\Models;

use Database\Factories\GenerationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'content_item_id', 'asset_id', 'type', 'parameters', 'status', 'idempotency_key', 'correlation_id', 'external_request_id', 'failure_code', 'failure_reason'])]
class GenerationRequest extends Model
{
    /** @use HasFactory<GenerationRequestFactory> */
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
        static::saving(function (GenerationRequest $r): void {
            $r->validateState();
            if ($r->content_item_id !== null) {
                $i = ContentItem::query()->find($r->content_item_id);
                if ($i === null || (int) $i->enterprise_id !== (int) $r->enterprise_id) {
                    throw new LogicException('Generation content item must belong to its enterprise.');
                }
            }if ($r->asset_id !== null) {
                $a = Asset::query()->find($r->asset_id);
                if ($a === null || (int) $a->enterprise_id !== (int) $r->enterprise_id) {
                    throw new LogicException('Generation asset must belong to its enterprise.');
                }
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

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<GenerationJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],self::STATUS_SUCCEEDED,self::STATUS_FAILED => [],default => throw new LogicException('Generation request has no valid lifecycle state.')
        };
        if (! in_array($status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true) || ! in_array($status, $allowed, true)) {
            throw new LogicException("Generation request cannot transition from [{$this->status}] to [{$status}].");
        }$this->status = $status;

        return $this;
    }

    public function fail(string $reason, ?string $code = null): static
    {
        $this->failure_reason = $reason;
        $this->failure_code = $code;

        return $this->transitionTo(self::STATUS_FAILED);
    }

    private function validateState(): void
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            throw new LogicException("Invalid generation request status [{$this->status}].");
        }if ($this->status === self::STATUS_SUCCEEDED && $this->failure_reason !== null) {
            throw new LogicException('A succeeded generation request cannot have a failure reason.');
        }if ($this->status === self::STATUS_FAILED && $this->failure_reason === null) {
            throw new LogicException('A failed generation request must have a failure reason.');
        }if ($this->exists && $this->getRawOriginal('status') === self::STATUS_FAILED && $this->status === self::STATUS_SUCCEEDED) {
            throw new LogicException('A failed generation request cannot be represented as succeeded.');
        }
    }
}