<?php

namespace App\Models;

use App\Models\Pivots\AudienceProduct;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

#[Fillable(['enterprise_id', 'name', 'slug', 'status'])]
class Product extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if (! in_array($product->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid product status [{$product->status}].");
            }

            if ($product->exists && $product->isDirty('enterprise_id')) {
                throw new LogicException('Product enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsToMany<Audience, $this, AudienceProduct> */
    public function audiences(): BelongsToMany
    {
        return $this->belongsToMany(Audience::class)->using(AudienceProduct::class)->withTimestamps();
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}