<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'content_item_id', 'name', 'type', 'status'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected static function booted(): void
    {
        static::saving(function (Asset $asset): void {
            if (! in_array($asset->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid asset status [{$asset->status}].");
            }
            if ($asset->content_item_id !== null) {
                $item = ContentItem::query()->find($asset->content_item_id);
                if ($item === null || (int) $item->enterprise_id !== (int) $asset->enterprise_id) {
                    throw new LogicException('Asset content item must belong to its enterprise.');
                }
            }
            if ($asset->exists && $asset->isDirty('enterprise_id')) {
                throw new LogicException('Asset enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return HasMany<AssetVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    /** @return HasMany<GenerationRequest, $this> */
    public function generationRequests(): HasMany
    {
        return $this->hasMany(GenerationRequest::class);
    }

    /** @return HasMany<RenderRequest, $this> */
    public function renderRequests(): HasMany
    {
        return $this->hasMany(RenderRequest::class);
    }
}