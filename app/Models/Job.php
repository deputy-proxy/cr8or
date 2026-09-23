<?php

namespace App\Models;

use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

#[Fillable(['workflow_id', 'name', 'idempotency_key', 'attempts', 'status', 'started_at', 'completed_at', 'failure_reason'])]
class Job extends Model
{
    protected $table = 'workflow_jobs';

    /** @use HasFactory<JobFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    private const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    protected static function booted(): void
    {
        static::saving(function (Job $job): void {
            $job->validateState();
        });
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class, 'workflow_job_id');
    }

    public function start(): static
    {
        $this->transitionTo(self::STATUS_RUNNING);

        return $this;
    }

    public function succeed(): static
    {
        $this->transitionTo(self::STATUS_SUCCEEDED);

        return $this;
    }

    public function fail(?string $reason = null): static
    {
        $this->failure_reason = $reason;
        $this->transitionTo(self::STATUS_FAILED);

        return $this;
    }

    public function retry(): static
    {
        if ($this->status !== self::STATUS_FAILED) {
            throw new LogicException("Job can only be retried from [failed], not [{$this->status}].");
        }

        $this->status = self::STATUS_PENDING;
        $this->failure_reason = null;
        $this->started_at = null;
        $this->completed_at = null;

        return $this;
    }

    public function transitionTo(string $status): static
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new LogicException("Invalid job status [{$status}].");
        }

        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
            self::STATUS_SUCCEEDED, self::STATUS_FAILED => [],
            default => throw new LogicException('Job has no valid lifecycle state.'),
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException(sprintf(
                'Job cannot transition from [%s] to [%s].',
                $this->status,
                $status,
            ));
        }

        $this->status = $status;

        if ($status === self::STATUS_RUNNING) {
            $this->started_at ??= Carbon::now();
            $this->attempts++;
        }

        if (in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
            $this->completed_at ??= Carbon::now();
        }

        return $this;
    }

    private function validateState(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new LogicException("Invalid job status [{$this->status}].");
        }

        if ($this->status === self::STATUS_SUCCEEDED && $this->failure_reason !== null) {
            throw new LogicException('A succeeded job cannot have a failure reason.');
        }

        if ($this->status === self::STATUS_FAILED && $this->failure_reason === null) {
            throw new LogicException('A failed job must have a failure reason.');
        }
    }
}
