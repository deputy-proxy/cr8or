<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\Revenue;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Revenue> */
class RevenueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'financial_account_id' => null,
            'transaction_id' => null,
            'amount' => fake()->randomFloat(4, 1, 100000),
            'currency' => 'EUR',
            'revenue_date' => fake()->date(),
            'source' => fake()->optional()->randomElement(['manual', 'invoice', 'bank']),
            'reference' => fake()->optional()->bothify('REV-########'),
            'description' => fake()->sentence(),
        ];
    }

    public function forAccount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'financial_account_id' => FinancialAccount::factory()->create(['enterprise_id' => $attributes['enterprise_id']])->getKey(),
        ]);
    }

    public function forTransaction(): static
    {
        return $this->state(function (array $attributes): array {
            $account = FinancialAccount::factory()->create(['enterprise_id' => $attributes['enterprise_id']]);
            $category = TransactionCategory::factory()->create(['enterprise_id' => $attributes['enterprise_id']]);
            $transaction = Transaction::factory()->create([
                'enterprise_id' => $attributes['enterprise_id'],
                'financial_account_id' => $account,
                'transaction_category_id' => $category,
            ]);

            return ['financial_account_id' => $account->getKey(), 'transaction_id' => $transaction->getKey()];
        });
    }
}