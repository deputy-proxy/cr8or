<?php

namespace App\Models;

use App\Models\Pivots\AudienceProduct;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['enterprise_id', 'name', 'slug', 'status'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

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