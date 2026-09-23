<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'content_item_id' => null, 'name' => fake()->words(3, true), 'type' => 'image', 'status' => Asset::STATUS_ACTIVE];
    }
}