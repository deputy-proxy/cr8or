<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'version', 'statement', 'status', 'effective_from', 'effective_to', 'supersedes_id', 'is_current'])]
class Mission extends Model
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
        static::saving(function (Mission $mission): void {
            if (! in_array($mission->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid mission status [{$mission->status}].");
            }

            if ($mission->version < 1) {
                throw new LogicException('Mission versions must start at one.');
            }

            if (Enterprise::query()->find($mission->enterprise_id) === null) {
                throw new LogicException('Mission must belong to an enterprise.');
            }

            if ($mission->exists) {
                foreach (['enterprise_id', 'version', 'statement', 'supersedes_id', 'effective_from'] as $field) {
                    if ($mission->isDirty($field)) {
                        throw new LogicException('Mission history is immutable; create a new version instead.');
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

    /** @return BelongsTo<Mission, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}