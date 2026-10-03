<?php

namespace App\Models;

use Database\Factories\WorkItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A WorkItem is a lightweight operational work subject.
 *
 * WorkItems belong to an Enterprise, may belong to a Project, and can be
 * associated with a Workflow. They intentionally do not carry Task hierarchy,
 * priority, or due-date semantics and are not a specialization of Task.
 */
#[Fillable(['enterprise_id', 'project_id', 'name', 'description', 'status'])]
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
