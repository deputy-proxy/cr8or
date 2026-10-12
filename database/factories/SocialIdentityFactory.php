<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SocialIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialIdentity> */
class SocialIdentityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => 'linkedin',
            'external_id' => fake()->unique()->bothify('member-####'),
            'name' => fake()->name(),
        ];
    }
}
