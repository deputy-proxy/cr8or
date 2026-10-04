<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'content_item_id', 'script_id', 'workflow_execution_id', 'agent_assignment_id', 'agent_execution_id', 'name', 'type', 'status', 'purpose', 'channel', 'platform', 'format', 'dimensions', 'duration_seconds', 'creative_brief'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $casts = [
        'dimensions' => 'array',
        'duration_seconds' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::saving(function (Asset $asset): void {
            if (! in_array($asset->status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid asset status [{$asset->status}].");
            }
            if ($asset->content_item_id !== null) {
                $item = ContentItem::query()->find($asset->content_item_id);
                if ($item === null || (int) $item->enterprise_id !== (int) $asset->enterprise_id) {
                    throw new LogicException('Asset content item must belong to its enterprise.');
                }
            }
            if ($asset->script_id !== null) {
                $script = Script::query()->find($asset->script_id);
                if ($script === null || (int) $script->contentItem->enterprise_id !== (int) $asset->enterprise_id) {
                    throw new LogicException('Asset script must belong to its enterprise.');
                }
                if ((int) $asset->content_item_id !== (int) $script->content_item_id) {
                    throw new LogicException('Planned asset content item must match its script.');
                }
                if ($asset->status !== self::STATUS_PENDING) {
                    throw new LogicException('Script-planned assets must remain pending until media generation is requested.');
                }
                if ($asset->workflow_execution_id !== null
                    && ($asset->agent_assignment_id !== null || $asset->agent_execution_id !== null)
                ) {
                    throw new LogicException('Script-planned assets cannot mix Workflow and Agent provenance.');
                }

                if ($asset->workflow_execution_id !== null) {
                    $workflowExecution = WorkflowExecution::query()->find($asset->workflow_execution_id);
                    if ($workflowExecution === null
                        || (int) $workflowExecution->enterprise_id !== (int) $asset->enterprise_id
                    ) {
                        throw new LogicException('Workflow asset provenance must belong to the asset enterprise.');
                    }
                } else {
                    if ($asset->agent_assignment_id === null || $asset->agent_execution_id === null) {
                        throw new LogicException('Script-planned assets require Workflow or Agent provenance.');
                    }

                    $execution = AgentExecution::query()->find($asset->agent_execution_id);
                    if ((int) $asset->agent_assignment_id !== (int) $script->agent_assignment_id
                        || $execution === null
                        || (int) $execution->agent_assignment_id !== (int) $asset->agent_assignment_id
                        || (int) $execution->enterprise_id !== (int) $asset->enterprise_id
                    ) {
                        throw new LogicException('Planned asset provenance must match its script assignment and enterprise.');
                    }
                }
            }

            if ($asset->agent_assignment_id !== null xor $asset->agent_execution_id !== null) {
                throw new LogicException('Asset Agent assignment and execution provenance must be supplied together.');
            }
            if ($asset->exists && $asset->isDirty('enterprise_id')) {
                throw new LogicException('Asset enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    /** @return BelongsTo<Script, $this> */
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    /** @return BelongsTo<WorkflowExecution, $this> */
    public function workflowExecution(): BelongsTo
    {
        return $this->belongsTo(WorkflowExecution::class);
    }

    /** @return BelongsTo<AgentAssignment, $this> */
    public function agentAssignment(): BelongsTo
    {
        return $this->belongsTo(AgentAssignment::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return HasMany<AssetVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    /** @return HasMany<GenerationRequest, $this> */
    public function generationRequests(): HasMany
    {
        return $this->hasMany(GenerationRequest::class);
    }

    /** @return HasMany<RenderRequest, $this> */
    public function renderRequests(): HasMany
    {
        return $this->hasMany(RenderRequest::class);
    }
}