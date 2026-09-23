<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\ContentSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentSeries> */
class ContentSeriesFactory extends Factory
{
    public function definition(): array
    {
        $campaign = Campaign::factory();

        return ['campaign_id' => $campaign, 'name' => fake()->sentence(3), 'description' => fake()->optional()->paragraph(), 'status' => 'draft'];
    }
}
