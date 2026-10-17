<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'enterprise_id',
    'version',
    'name',
    'website',
    'positioning',
    'strengths',
    'weaknesses',
    'status',
    'effective_from',
    'effective_to',
    'supersedes_id',
    'is_current',
])]
class Competitor extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'strengths' => 'array',
            'weaknesses' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Competitor $competitor): void {
            if (! in_array($competitor->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid competitor status [{$competitor->status}].");
            }

            if ($competitor->version < 1) {
                throw new LogicException('Competitor versions must start at one.');
            }

            if ((bool) $competitor->is_current !== ($competitor->status === self::STATUS_ACTIVE)) {
                throw new LogicException('Only active competitor versions may be current.');
            }

            if ($competitor->supersedes_id !== null) {
                $predecessor = self::query()->find($competitor->supersedes_id);

                if ($predecessor === null
                    || (int) $predecessor->enterprise_id !== (int) $competitor->enterprise_id
                    || $predecessor->version >= $competitor->version) {
                    throw new LogicException('Competitor version lineage must point to an earlier version in the same enterprise.');
                }
            }

            if (Enterprise::query()->find($competitor->enterprise_id) === null) {
                throw new LogicException('Competitor must belong to an enterprise.');
            }

            if ($competitor->exists) {
                foreach ([
                    'enterprise_id',
                    'version',
                    'name',
                    'website',
                    'positioning',
                    'strengths',
                    'weaknesses',
                    'supersedes_id',
                    'effective_from',
                ] as $field) {
                    if ($competitor->isDirty($field)) {
                        throw new LogicException('Competitor history is immutable; create a new version instead.');
                    }
                }
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Competitor, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasMany<Competitor, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }
}