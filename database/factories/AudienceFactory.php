<?php

namespace Database\Factories;

use App\Models\Audience;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Audience> */
class AudienceFactory extends Factory
{
    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->words(3, true), 'description' => fake()->optional()->paragraph(), 'status' => 'active'];
    }
}