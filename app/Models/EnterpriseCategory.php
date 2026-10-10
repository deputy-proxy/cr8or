<?php

namespace App\Models;

use Database\Factories\EnterpriseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['organization_id', 'name', 'slug', 'description', 'sort_order'])]
class EnterpriseCategory extends Model
{
    /** @use HasFactory<EnterpriseCategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if ($category->exists && $category->isDirty('organization_id')) {
                throw new LogicException('Enterprise category organization cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Enterprise, $this> */
    public function enterprises(): HasMany
    {
        return $this->hasMany(Enterprise::class);
    }
}
