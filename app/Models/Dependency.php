<?php

namespace App\Models;

use Database\Factories\DependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['enterprise_id', 'project_id', 'predecessor_type', 'predecessor_id', 'successor_type', 'successor_id', 'type'])]
class Dependency extends Model
{
    /** @use HasFactory<DependencyFactory> */
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

    /** @return MorphTo<Model, $this> */
    public function predecessor(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function successor(): MorphTo
    {
        return $this->morphTo();
    }
}
