<?php

namespace App\Models;

use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $enterprise_id
 * @property \Carbon\CarbonImmutable|null $target_date
 */
#[Fillable(['enterprise_id', 'name', 'description', 'target_date', 'status'])]
class Milestone extends Model
{
    protected $attributes = ['status' => 'planned'];

    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['target_date' => 'immutable_date', 'closed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
