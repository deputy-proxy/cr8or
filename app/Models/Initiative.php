<?php

namespace App\Models;

use Database\Factories\InitiativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['strategy_id', 'name', 'description'])]
class Initiative extends Model
{
    /** @use HasFactory<InitiativeFactory> */
    use HasFactory;

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
