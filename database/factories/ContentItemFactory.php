<?php

namespace Database\Factories;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentItem> */
class ContentItemFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ContentItem $item): void {
            $item->enterprise_id = $item->campaign->enterprise_id;
        });
    }

    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'campaign_id' => Campaign::factory(),
            'content_series_id' => null,
            'channel_id' => null,
            'audience_id' => null,
            'title' => fake()->sentence(5),
            'body' => fake()->paragraphs(2, true),
            'status' => 'draft',
        ];
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn () => ['enterprise_id' => $campaign->enterprise_id, 'campaign_id' => $campaign->id]);
    }

    public function forSeries(ContentSeries $series): static
    {
        return $this->state(fn () => ['enterprise_id' => $series->campaign->enterprise_id, 'campaign_id' => $series->campaign_id, 'content_series_id' => $series->id]);
    }

    public function forChannel(Channel $channel): static
    {
        return $this->state(fn () => ['enterprise_id' => $channel->enterprise_id, 'channel_id' => $channel->id]);
    }

    public function forAudience(Audience $audience): static
    {
        return $this->state(fn () => ['enterprise_id' => $audience->enterprise_id, 'audience_id' => $audience->id]);
    }
}
