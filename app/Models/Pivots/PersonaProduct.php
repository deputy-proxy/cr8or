<?php

namespace App\Models\Pivots;

use App\Models\Persona;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

class PersonaProduct extends Pivot
{
    protected $table = 'persona_product';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::saving(function (self $pivot): void {
            $persona = Persona::query()->find((int) $pivot->persona_id);
            $product = Product::query()->find((int) $pivot->product_id);

            if ($persona === null || $product === null || (int) $persona->enterprise_id !== (int) $product->enterprise_id) {
                throw new LogicException('Persona and product must belong to the same enterprise.');
            }
        });
    }
}