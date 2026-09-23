<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->randomElement(['Website', 'Instagram', 'LinkedIn', 'Facebook']), 'type' => 'social', 'status' => 'active'];
    }
}