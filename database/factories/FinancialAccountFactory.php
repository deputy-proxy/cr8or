<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialAccount> */
class FinancialAccountFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Account';

        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => $name,
            'type' => fake()->randomElement(['bank', 'cash', 'credit_card', 'other']),
            'status' => FinancialAccount::STATUS_ACTIVE,
            'currency' => 'EUR',
        ];
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => FinancialAccount::STATUS_INACTIVE,
        ]);
    }
}
