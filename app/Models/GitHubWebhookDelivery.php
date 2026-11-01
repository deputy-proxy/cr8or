<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $payload
 */
#[Fillable(['delivery_id', 'organization_id', 'enterprise_id', 'event_type', 'action', 'installation_id', 'github_repository_id', 'integration_connection_id', 'payload_hash', 'payload', 'status', 'failure_reason', 'received_at', 'processed_at'])]
class GitHubWebhookDelivery extends Model
{
    protected $table = 'github_webhook_deliveries';

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
