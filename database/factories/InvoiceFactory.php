<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Enterprise;
use App\Models\Invoice;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'customer_id' => fn (array $attributes): int => Customer::factory()->create(['enterprise_id' => $attributes['enterprise_id']])->getKey(),
            'partner_id' => null,
            'invoice_number' => fake()->unique()->bothify('INV-####'),
            'issue_date' => fake()->date(),
            'due_date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'total' => fake()->randomFloat(4, 1, 100000),
            'currency' => 'EUR',
            'status' => Invoice::STATUS_DRAFT,
            'counterparty_name_snapshot' => null,
            'counterparty_email_snapshot' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(['status' => Invoice::STATUS_ISSUED]);
    }

    public function partner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'customer_id' => null,
            'partner_id' => Partner::factory()->create(['enterprise_id' => $attributes['enterprise_id']])->getKey(),
        ]);
    }
}