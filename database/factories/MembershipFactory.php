<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'role' => MembershipRole::Member,
        ];
    }

    public function owner(): static
    {
        return $this->state([
            'role' => MembershipRole::Owner,
        ]);
    }

    public function admin(): static
    {
        return $this->state([
            'role' => MembershipRole::Admin,
        ]);
    }
}
