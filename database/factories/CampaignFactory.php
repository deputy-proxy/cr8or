<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'marketing_strategy_id' => MarketingStrategy::factory(), 'name' => fake()->sentence(3), 'description' => fake()->optional()->paragraph(), 'status' => 'draft'];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Campaign $campaign) {
            $campaign->enterprise_id = $campaign->marketingStrategy->enterprise_id;
        })->afterCreating(function (Campaign $campaign) {
            $campaign->enterprise_id = $campaign->marketingStrategy->enterprise_id;
            $campaign->saveQuietly();
        });
    }
}
