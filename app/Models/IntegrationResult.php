<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'integration_job_id',
    'integration_connection_id',
    'organization_id',
    'enterprise_id',
    'provider',
    'operation',
    'external_job_id',
    'external_result_id',
    'status',
    'source',
    'dedupe_key',
    'correlation_id',
    'payload',
    'failure_code',
    'failure_reason',
    'occurred_at',
    'received_at',
    'processed_at',
    'processing_status',
])]
class IntegrationResult extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_UNKNOWN = 'unknown';

    public const PROCESSING_APPLIED = 'applied';

    public const PROCESSING_IGNORED = 'ignored';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $result): void {
            $job = IntegrationJob::query()->find($result->integration_job_id);

            if ($job === null
                || (int) $job->integration_connection_id !== (int) $result->integration_connection_id
                || (int) $job->organization_id !== (int) $result->organization_id
                || (int) $job->enterprise_id !== (int) $result->enterprise_id
                || $job->provider !== $result->provider
                || $job->operation !== $result->operation
            ) {
                throw new LogicException('Integration result must remain attached to its originating integration job.');
            }

            if (! in_array($result->status, [
                self::STATUS_PENDING,
                self::STATUS_SUCCEEDED,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
                self::STATUS_EXPIRED,
                self::STATUS_UNKNOWN,
            ], true)) {
                throw new LogicException("Invalid integration result status [{$result->status}].");
            }

            if (! in_array($result->source, ['webhook', 'poll'], true)) {
                throw new LogicException("Invalid integration result source [{$result->source}].");
            }

            if (! in_array($result->processing_status, [self::PROCESSING_APPLIED, self::PROCESSING_IGNORED], true)) {
                throw new LogicException("Invalid integration result processing status [{$result->processing_status}].");
            }

            if ($result->exists) {
                throw new LogicException('Integration results are historical and immutable.');
            }
        });
    }

    /** @return BelongsTo<IntegrationJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(IntegrationJob::class, 'integration_job_id');
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
}