<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Transformation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transformation> */
class TransformationFactory extends Factory
{
    protected $model = Transformation::class;

    public function definition(): array
    {
        $a = Asset::factory();
        $v = AssetVersion::factory()->for($a);

        return ['asset_id' => $a, 'source_version_id' => $v, 'output_version_id' => null, 'type' => 'resize', 'parameters' => []];
    }
}
