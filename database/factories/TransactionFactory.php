<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'financial_account_id' => fn (array $attributes): int => FinancialAccount::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
            'transaction_category_id' => fn (array $attributes): int => TransactionCategory::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
            'financial_period_id' => fn (array $attributes): int => FinancialPeriod::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
            'amount' => fake()->randomFloat(4, -100000, 100000),
            'transaction_date' => fake()->date(),
            'description' => fake()->sentence(),
            'reference' => fake()->optional()->bothify('TX-########'),
        ];
    }
}
