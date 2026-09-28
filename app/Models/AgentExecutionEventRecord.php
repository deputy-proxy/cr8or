<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'organization_id', 'enterprise_id', 'agent_assignment_id', 'agent_execution_id',
    'actor_id', 'event_type', 'version', 'visibility', 'correlation_id', 'causation_id', 'provenance', 'data', 'occurred_at',
])]
class AgentExecutionEventRecord extends Model
{
    protected $table = 'agent_execution_events';

    /** @return BelongsTo<AgentExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'agent_execution_id');
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

    protected function casts(): array
    {
        return [
            'provenance' => 'array',
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}