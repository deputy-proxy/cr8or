<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'publication_id', 'idempotency_key', 'attempts', 'status', 'failure_code', 'failure_reason', 'started_at', 'completed_at', 'external_id', 'external_url', 'adapter_key', 'network', 'destination_external_id', 'retryable'])]
class PublishingJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    protected $casts = ['retryable' => 'boolean'];

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
        }

        $this->retryable = false;
        $this->status = self::STATUS_RUNNING;
        $this->attempts++;
        $this->started_at ??= now();

        return $this;
    }

    public function succeed(): static
    {
        if (! in_array($this->status, [self::STATUS_RUNNING, self::STATUS_UNKNOWN], true)) {
            throw new LogicException('Publishing job cannot succeed from its current state.');
        }

        $this->status = self::STATUS_SUCCEEDED;
        $this->retryable = false;
        $this->completed_at ??= now();

        return $this;
    }

    public function markUnknown(?string $code, ?string $reason): static
    {
        if ($this->status !== self::STATUS_RUNNING) {
            throw new LogicException('Only a running publishing job can become ambiguous.');
        }

        $this->status = self::STATUS_UNKNOWN;
        $this->retryable = false;
        $this->failure_code = $code;
        $this->failure_reason = $reason;
        $this->completed_at ??= now();

        return $this;
    }

    public function fail(?string $c, ?string $r): static
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_UNKNOWN], true)) {
            throw new LogicException('Publishing job cannot fail from its current state.');
        }

        $this->status = self::STATUS_FAILED;
        $this->retryable = false;
        $this->failure_code = $c;
        $this->failure_reason = $r;
        $this->completed_at ??= now();

        return $this;
    }
}