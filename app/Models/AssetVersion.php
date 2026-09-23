<?php

namespace App\Models;

use Database\Factories\AssetVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['asset_id', 'version', 'disk', 'path', 'mime_type', 'size', 'checksum', 'metadata', 'external_reference'])]
class AssetVersion extends Model
{
    /** @use HasFactory<AssetVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (AssetVersion $v): void {
            if ($v->version < 1) {
                throw new LogicException('Asset version must be positive.');
            }if (! Asset::query()->whereKey($v->asset_id)->exists()) {
                throw new LogicException('Asset version requires an asset.');
            }
        });
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
