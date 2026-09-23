<?php

namespace Database\Factories;

use App\Models\Statement;
use App\Models\StatementEntry;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatementEntry> */
class StatementEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'enterprise_id' => null,
            'statement_id' => Statement::factory(),
            'financial_account_id' => null,
            'transaction_id' => null,
            'source' => 'manual',
            'source_reference' => 'entry-'.Str::uuid(),
            'amount' => fake()->randomFloat(4, -100000, 100000),
            'entry_date' => fake()->date(),
            'description' => fake()->sentence(),
            'reference' => fake()->optional()->bothify('ST-########'),
            'metadata' => ['imported' => true],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (StatementEntry $entry): void {
            /** @var Statement $statement */
            $statement = Statement::query()->findOrFail($entry->statement_id);

            $entry->organization_id ??= $statement->organization_id;
            $entry->enterprise_id ??= $statement->enterprise_id;
            $entry->financial_account_id ??= $statement->financial_account_id;
        });
    }

    public function linkedToTransaction(?Transaction $transaction = null): static
    {
        return $this->state(function (array $attributes) use ($transaction): array {
            /** @var Statement $statement */
            $statement = Statement::query()->findOrFail($attributes['statement_id']);
            $transaction ??= Transaction::factory()->create([
                'enterprise_id' => $statement->enterprise_id,
                'financial_account_id' => $statement->financial_account_id,
            ]);

            return [
                'transaction_id' => $transaction->getKey(),
            ];
        });
    }
}