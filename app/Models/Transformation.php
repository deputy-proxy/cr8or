<?php

namespace App\Models;

use Database\Factories\TransformationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['asset_id', 'source_version_id', 'output_version_id', 'type', 'parameters'])]
class Transformation extends Model
{
    /** @use HasFactory<TransformationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'source_version_id');
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function outputVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'output_version_id');
    }
}