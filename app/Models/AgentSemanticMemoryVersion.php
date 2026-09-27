<?php

namespace App\Models;

use Database\Factories\AgentSemanticMemoryVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent_semantic_memory_id',
    'organization_id',
    'enterprise_id',
    'agent_descriptor_id',
    'statement',
    'confidence',
    'status',
    'conflict_memory_ids',
    'provenance',
    'change_type',
    'changed_by_user_id',
    'recorded_at',
])]
class AgentSemanticMemoryVersion extends Model
{
    /** @use HasFactory<AgentSemanticMemoryVersionFactory> */
    use HasFactory;

    /** @return BelongsTo<AgentSemanticMemory, $this> */
    public function memory(): BelongsTo
    {
        return $this->belongsTo(AgentSemanticMemory::class, 'agent_semantic_memory_id');
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

    /** @return BelongsTo<AgentDescriptor, $this> */
    public function agentDescriptor(): BelongsTo
    {
        return $this->belongsTo(AgentDescriptor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'conflict_memory_ids' => 'array',
            'provenance' => 'array',
            'recorded_at' => 'datetime',
        ];
    }
}
