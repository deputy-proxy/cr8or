<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['integration_connection_id', 'organization_id', 'enterprise_id', 'content_item_id', 'asset_id', 'agent_execution_id', 'provider', 'operation', 'idempotency_key', 'status', 'external_job_id', 'failure_code', 'failure_reason', 'correlation_id', 'attempts'])]
class IntegrationJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::saving(function (IntegrationJob $job): void {
            if (! in_array($job->status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)) {
                throw new LogicException("Invalid integration job status [{$job->status}].");
            }

            $connection = IntegrationConnection::query()->find($job->integration_connection_id);
            if ($connection === null
                || (int) $connection->organization_id !== (int) $job->organization_id
                || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $job->enterprise_id)
            ) {
                throw new LogicException('Integration job connection must belong to its organization and enterprise.');
            }

            foreach (['content_item_id' => ContentItem::class, 'asset_id' => Asset::class] as $field => $model) {
                if ($job->{$field} === null) {
                    continue;
                }
                $record = $model::query()->find($job->{$field});
                if ($record === null || (int) $record->enterprise_id !== (int) $job->enterprise_id) {
                    throw new LogicException("Integration job {$field} must belong to its enterprise.");
                }
            }

            if ($job->status === self::STATUS_SUCCEEDED && $job->failure_reason !== null) {
                throw new LogicException('A succeeded integration job cannot have a failure reason.');
            }
            if ($job->status === self::STATUS_FAILED && $job->failure_reason === null) {
                throw new LogicException('A failed integration job must have a failure reason.');
            }
            if ($job->exists && $job->getRawOriginal('status') === self::STATUS_FAILED && $job->status === self::STATUS_SUCCEEDED) {
                throw new LogicException('A failed integration job cannot be represented as succeeded.');
            }
        });
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
            self::STATUS_RUNNING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
            self::STATUS_SUCCEEDED, self::STATUS_FAILED => [],
            default => throw new LogicException('Integration job has no valid lifecycle state.'),
        };

        if (! in_array($status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)
            || ! in_array($status, $allowed, true)
        ) {
            throw new LogicException("Integration job cannot transition from [{$this->status}] to [{$status}].");
        }

        $this->status = $status;

        return $this;
    }

    public function fail(string $reason, string $code): static
    {
        $this->failure_reason = $reason;
        $this->failure_code = $code;

        return $this->transitionTo(self::STATUS_FAILED);
    }
}
