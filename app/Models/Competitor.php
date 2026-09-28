<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}