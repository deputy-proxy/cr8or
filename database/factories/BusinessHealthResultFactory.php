<?php

namespace Database\Factories;

use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\FinancialReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessHealthResult> */
class BusinessHealthResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'financial_report_id' => fn (array $attributes): int => FinancialReport::factory()
                ->create(['enterprise_id' => $attributes['enterprise_id']])
                ->getKey(),
            'health_status' => 'healthy',
            'metrics' => [],
            'source_snapshot' => [],
            'evaluated_at' => now(),
        ];
    }
}
