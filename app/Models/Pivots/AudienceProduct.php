<?php

namespace App\Models\Pivots;

use App\Models\Audience;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

class AudienceProduct extends Pivot
{
    protected $table = 'audience_product';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            $audience = Audience::query()->find((int) $pivot->audience_id);
            $product = Product::query()->find((int) $pivot->product_id);

            if ($audience === null || $product === null || (int) $audience->enterprise_id !== (int) $product->enterprise_id) {
                throw new LogicException('Audience and product must belong to the same enterprise.');
            }
        });
    }
}