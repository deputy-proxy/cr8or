<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'version', 'statement', 'status', 'effective_from', 'effective_to', 'supersedes_id', 'is_current'])]
class Vision extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vision $vision): void {
            if (! in_array($vision->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid vision status [{$vision->status}].");
            }

            if ($vision->version < 1) {
                throw new LogicException('Vision versions must start at one.');
            }

            $enterprise = Enterprise::query()->find($vision->enterprise_id);

            if ($enterprise === null) {
                throw new LogicException('Vision must belong to an enterprise.');
            }

            if ($vision->exists) {
                foreach (['enterprise_id', 'version', 'statement', 'supersedes_id', 'effective_from'] as $field) {
                    if ($vision->isDirty($field)) {
                        throw new LogicException('Vision history is immutable; create a new version instead.');
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

    /** @return BelongsTo<Vision, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}