<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'key_id',
    'idempotency_key',
    'capability',
    'actor_id',
    'organization_id',
    'enterprise_id',
    'correlation_id',
    'payload',
    'response',
    'status',
    'failure_code',
    'failure_reason',
    'processed_at',
])]
class CommandWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $delivery): void {
            if (! in_array($delivery->status, [
                self::STATUS_PENDING,
                self::STATUS_SUCCEEDED,
                self::STATUS_FAILED,
            ], true)) {
                throw new LogicException("Invalid command webhook delivery status [{$delivery->status}].");
            }

            if ($delivery->exists
                && $delivery->isDirty([
                    'key_id',
                    'idempotency_key',
                    'capability',
                    'actor_id',
                    'organization_id',
                    'enterprise_id',
                    'payload',
                ])) {
                throw new LogicException('Command webhook delivery identity is immutable.');
            }
        });
    }
}