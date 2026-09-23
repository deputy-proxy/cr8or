<?php

namespace Database\Factories;

use App\Models\AssetVersion;
use App\Models\MediaMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaMetadata> */
class MediaMetadataFactory extends Factory
{
    protected $model = MediaMetadata::class;

    public function definition(): array
    {
        return ['asset_version_id' => AssetVersion::factory(), 'width' => 1920, 'height' => 1080, 'duration_seconds' => null, 'codec' => null, 'frame_rate' => null, 'metadata' => []];
    }
}
