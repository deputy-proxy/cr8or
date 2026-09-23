<?php

namespace App\Models;

use Database\Factories\RenderOutputFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['render_request_id', 'asset_version_id', 'external_output_id', 'disk', 'path', 'mime_type', 'size', 'checksum', 'metadata'])]
class RenderOutput extends Model
{
    /** @use HasFactory<RenderOutputFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<RenderRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RenderRequest::class, 'render_request_id');
    }

    /** @return BelongsTo<AssetVersion, $this> */
    public function assetVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class);
    }
}