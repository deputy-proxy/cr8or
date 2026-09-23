<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'financial_account_id' => null,
            'transaction_id' => null,
            'transaction_category_id' => null,
            'amount' => fake()->randomFloat(4, 1, 100000),
            'currency' => 'EUR',
            'expense_date' => fake()->date(),
            'source' => fake()->optional()->randomElement(['manual', 'bill', 'bank']),
            'reference' => fake()->optional()->bothify('EXP-########'),
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

            return [
                'financial_account_id' => $account->getKey(),
                'transaction_id' => $transaction->getKey(),
                'transaction_category_id' => $category->getKey(),
            ];
        });
    }
}
