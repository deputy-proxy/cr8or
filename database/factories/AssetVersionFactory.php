<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssetVersion> */
class AssetVersionFactory extends Factory
{
    protected $model = AssetVersion::class;

    public function definition(): array
    {
        return ['asset_id' => Asset::factory(), 'version' => 1, 'disk' => 'local', 'path' => fake()->slug(3).'.bin', 'mime_type' => 'application/octet-stream', 'size' => 100, 'checksum' => hash('sha256', fake()->uuid()), 'metadata' => [], 'external_reference' => null];
    }
}