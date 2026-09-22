<?php

namespace App\Models;

use Database\Factories\AgentPermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_assignment_id', 'capability', 'requires_approval'])]
class AgentPermission extends Model
{
    /** @use HasFactory<AgentPermissionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requires_approval' => 'boolean'];
    }

    /** @return BelongsTo<AgentAssignment, $this> */
    public function agentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class);
    }
}