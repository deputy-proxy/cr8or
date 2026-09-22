<?php

namespace App\Models;

use Database\Factories\StrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['objective_id', 'name', 'description'])]
class Strategy extends Model
{
    /** @use HasFactory<StrategyFactory> */
    use HasFactory;

    /** @return BelongsTo<Objective, $this> */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /** @return HasMany<Plan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
