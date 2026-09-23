<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Budget> */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'financial_period_id' => fn (array $attributes): int => FinancialPeriod::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
            'financial_account_id' => null,
            'transaction_category_id' => null,
            'name' => fake()->unique()->sentence(3),
            'planned_amount' => fake()->randomFloat(4, 0, 100000),
            'currency' => 'EUR',
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forAccount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'financial_account_id' => FinancialAccount::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
        ]);
    }

    public function forCategory(): static
    {
        return $this->state(fn (array $attributes): array => [
            'transaction_category_id' => TransactionCategory::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
        ]);
    }
}
