<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\Statement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Statement> */
class StatementFactory extends Factory
{
    public function definition(): array
    {
        $enterprise = Enterprise::factory()->create();
        $periodStart = fake()->dateTimeBetween('-12 months', '-2 months');
        $periodEnd = (clone $periodStart)->modify('+1 month')->modify('-1 day');

        return [
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'financial_account_id' => FinancialAccount::factory()
                ->create(['enterprise_id' => $enterprise->getKey()])
                ->getKey(),
            'source' => 'manual',
            'source_reference' => 'statement-'.Str::uuid(),
            'statement_date' => fake()->date(),
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
            'metadata' => ['imported' => true],
        ];
    }
}