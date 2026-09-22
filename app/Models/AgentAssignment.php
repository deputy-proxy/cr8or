<?php

namespace App\Models;

use Database\Factories\AgentAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['agent_descriptor_id', 'organization_id', 'enterprise_id', 'enabled'])]
class AgentAssignment extends Model
{
    /** @use HasFactory<AgentAssignmentFactory> */
    use HasFactory;

    /** @return BelongsTo<AgentDescriptor, $this> */
    public function agentDescriptor(): BelongsTo
    {
        return $this->belongsTo(AgentDescriptor::class);
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

    /** @return HasMany<Assignment, $this> */
    public function workAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @return HasMany<AgentPermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(AgentPermission::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
