<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Publication> */
class PublicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'content_item_id' => ContentItem::factory(),
            'channel_id' => Channel::factory(),
            'social_account_id' => SocialAccount::factory(),
            'status' => Publication::STATUS_SCHEDULED,
            'idempotency_key' => fake()->unique()->uuid(),
            'scheduled_at' => now()->addHour(),
        ];
    }
}
