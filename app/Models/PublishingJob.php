<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'publication_id', 'idempotency_key', 'attempts', 'status', 'failure_code', 'failure_reason', 'started_at', 'completed_at'])]
class PublishingJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function start(): static
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new LogicException('Publishing job is not pending.');
        } $this->status = self::STATUS_RUNNING;
        $this->attempts++;
        $this->started_at ??= now();

        return $this;
    }

    public function succeed(): static
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->completed_at ??= now();

        return $this;
    }

    public function fail(?string $c, ?string $r): static
    {
        $this->status = self::STATUS_FAILED;
        $this->failure_code = $c;
        $this->failure_reason = $r;
        $this->completed_at ??= now();

        return $this;
    }
}