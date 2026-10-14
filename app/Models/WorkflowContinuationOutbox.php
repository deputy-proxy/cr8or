<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_key', 'event_type', 'organization_id', 'enterprise_id', 'actor_id', 'aggregate_id', 'payload', 'occurred_at', 'processed_at', 'attempts', 'last_error'])]
/**
 * @property array<string, mixed> $payload
 * @property string $event_type
 * @property int $enterprise_id
 * @property int|null $actor_id
 * @property \Illuminate\Support\Carbon|null $processed_at
 */
class WorkflowContinuationOutbox extends Model
{
    protected $table = 'workflow_continuation_outbox';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
