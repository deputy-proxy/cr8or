<?php

namespace App\Models;

use Database\Factories\WorkItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['enterprise_id', 'project_id', 'name', 'description', 'status'])]
/**
 * An operational work subject for governed execution.
 *
 * WorkItems are lightweight Enterprise/Project-scoped work records with a direct
 * Workflow association. They intentionally do not model Task hierarchy, priority,
 * or due-date semantics. A WorkItem can therefore represent the operational target
 * of WorkItem capabilities without replacing the planning-oriented Task concept.
 */
class WorkItem extends Model
{
    /** @use HasFactory<WorkItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<Workflow, $this> */
    public function workflow(): HasOne
    {
        return $this->hasOne(Workflow::class);
    }
}