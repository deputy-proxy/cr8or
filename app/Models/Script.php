<?php

namespace App\Models;

use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['content_item_id', 'agent_assignment_id', 'agent_execution_id', 'title', 'body', 'asset_requirements'])]
class Script extends Model
{
    /** @use HasFactory<ScriptFactory> */
    use HasFactory;

    protected $casts = ['asset_requirements' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (Script $script): void {
            if ($script->asset_requirements !== null) {
                foreach ($script->asset_requirements as $requirement) {
                    foreach (['type', 'purpose', 'channel', 'platform', 'format', 'creative_brief'] as $field) {
                        if (! is_array($requirement) || ! isset($requirement[$field]) || ! is_string($requirement[$field]) || trim($requirement[$field]) === '') {
                            throw new LogicException("Script asset requirement field [{$field}] is required.");
                        }
                    }
                }
            }

            $item = ContentItem::query()->find($script->content_item_id);
            if ($item === null) {
                throw new LogicException('Script content item must exist.');
            }

            if ($script->agent_assignment_id === null xor $script->agent_execution_id === null) {
                throw new LogicException('Script Agent assignment and execution provenance must be supplied together.');
            }

            if ($script->agent_assignment_id !== null) {
                $assignment = AgentAssignment::query()->find($script->agent_assignment_id);
                $execution = AgentExecution::query()->find($script->agent_execution_id);

                if ($assignment === null || $execution === null) {
                    throw new LogicException('Script Agent provenance must reference existing records.');
                }

                if ((int) $assignment->enterprise_id !== (int) $item->enterprise_id
                    || (int) $execution->enterprise_id !== (int) $item->enterprise_id
                    || (int) $execution->agent_assignment_id !== (int) $assignment->getKey()
                ) {
                    throw new LogicException('Script Agent provenance must belong to the content item enterprise and assignment.');
                }
            }

            if ($script->exists && $script->isDirty('content_item_id')) {
                throw new LogicException('Script content item ownership cannot be changed.');
            }

            foreach (['agent_assignment_id', 'agent_execution_id'] as $field) {
                if ($script->exists && $script->getOriginal($field) !== null && $script->isDirty($field)) {
                    throw new LogicException("Script {$field} provenance cannot be replaced.");
                }
            }
        });
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<AgentAssignment, $this> */
    public function agentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }
}