<?php

namespace App\Models;

use Database\Factories\ObjectiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['enterprise_id', 'goal_id', 'kpi_id', 'name', 'description'])]
class Objective extends Model
{
    /** @use HasFactory<ObjectiveFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return BelongsTo<Kpi, $this> */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    /** @return HasMany<Strategy, $this> */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }
}
