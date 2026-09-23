<?php

namespace Database\Factories;

use App\Models\AssetVersion;
use App\Models\RenderOutput;
use App\Models\RenderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RenderOutput> */
class RenderOutputFactory extends Factory
{
    protected $model = RenderOutput::class;

    public function definition(): array
    {
        return ['render_request_id' => RenderRequest::factory(), 'asset_version_id' => AssetVersion::factory(), 'external_output_id' => null, 'disk' => 'local', 'path' => 'rendered/test.bin', 'mime_type' => 'application/octet-stream', 'size' => 100, 'checksum' => hash('sha256', 'test'), 'metadata' => []];
    }
}
