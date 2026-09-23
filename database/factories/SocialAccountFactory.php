<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        $e = Enterprise::factory();
        $c = Channel::factory()->for($e);

        return [
            'enterprise_id' => $e,
            'channel_id' => $c,
            'provider' => 'postiz',
            'name' => fake()->company(),
            'external_id' => fake()->unique()->bothify('postiz-####'),
            'status' => SocialAccount::STATUS_ACTIVE,
        ];
    }
}
