<?php

namespace App\Models;

use Database\Factories\MediaMetadataFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['asset_version_id', 'width', 'height', 'duration_seconds', 'codec', 'frame_rate', 'metadata'])]
class MediaMetadata extends Model
{
    /** @use HasFactory<MediaMetadataFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'duration_seconds' => 'decimal:3', 'frame_rate' => 'decimal:3'];
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function assetVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class);
    }
}
