<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use LogicException;

#[Fillable(['enterprise_id', 'publication_id', 'idempotency_key', 'attempts', 'status', 'failure_code', 'failure_reason', 'started_at', 'completed_at', 'external_id', 'external_url', 'adapter_key', 'network', 'destination_external_id', 'retryable', 'adapter_state_encrypted'])]
class PublishingJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    protected $casts = ['retryable' => 'boolean'];

    protected $hidden = ['adapter_state_encrypted'];

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

    /** @return array<string, mixed> */
    public function adapterState(): array
    {
        $encrypted = $this->getAttribute('adapter_state_encrypted');
        if (! is_string($encrypted) || $encrypted === '') {
            return [];
        }

        try {
            $state = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new LogicException('Publishing adapter state could not be decrypted or decoded.', previous: $exception);
        }

        if (! is_array($state)) {
            throw new LogicException('Publishing adapter state must be a JSON object.');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function setAdapterState(array $state): static
    {
        if (! in_array($this->status, [self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_UNKNOWN], true)) {
            throw new LogicException('Adapter state can only be persisted for a started publishing job.');
        }

        $this->adapter_state_encrypted = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));

        return $this;
    }

    public function clearAdapterState(): static
    {
        $this->adapter_state_encrypted = null;

        return $this;
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