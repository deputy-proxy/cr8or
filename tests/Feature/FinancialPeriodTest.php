<?php

use App\Models\Budget;
use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialPeriod;
use App\Models\Revenue;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('persists a financial period with explicit reporting boundaries', function () {
    $enterprise = Enterprise::factory()->create();

    $period = FinancialPeriod::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'FY2026',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);

    expect($period->enterprise->is($enterprise))->toBeTrue()
        ->and($period->period_start->equalTo(Carbon::parse('2026-01-01')))->toBeTrue()
        ->and($period->period_end->equalTo(Carbon::parse('2026-12-31')))->toBeTrue()
        ->and($period->status)->toBe(FinancialPeriod::STATUS_ACTIVE);
});

it('rejects an invalid financial period range', function () {
    expect(fn () => FinancialPeriod::factory()->create([
        'period_start' => '2026-12-31',
        'period_end' => '2026-01-01',
    ]))->toThrow(LogicException::class);
});

it('preserves historical period identity while allowing lifecycle changes', function () {
    $period = FinancialPeriod::factory()->create([
        'name' => 'September 2026',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
    ]);

    $period->name = 'Renamed';
    $period->period_start = '2026-09-02';
    $period->period_end = '2026-10-01';
    $period->status = FinancialPeriod::STATUS_CLOSED;
    $period->save();
    $period->refresh();

    expect($period->name)->toBe('September 2026')
        ->and($period->period_start->toDateString())->toBe('2026-09-01')
        ->and($period->period_end->toDateString())->toBe('2026-09-30')
        ->and($period->status)->toBe(FinancialPeriod::STATUS_CLOSED);
});

it('exposes all financial records associated with a period', function () {
    $enterprise = Enterprise::factory()->create();
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create(['enterprise_id' => $enterprise, 'financial_period_id' => $period]);
    $revenue = Revenue::factory()->create(['enterprise_id' => $enterprise, 'financial_period_id' => $period]);
    $expense = Expense::factory()->create(['enterprise_id' => $enterprise, 'financial_period_id' => $period]);
    $budget = Budget::factory()->create(['enterprise_id' => $enterprise, 'financial_period_id' => $period]);

    expect($period->transactions()->pluck('id'))->toContain($transaction->id)
        ->and($period->revenues()->pluck('id'))->toContain($revenue->id)
        ->and($period->expenses()->pluck('id'))->toContain($expense->id)
        ->and($period->budgets()->pluck('id'))->toContain($budget->id);
});

it('keeps the financial period schema explicit', function () {
    expect(Schema::getColumnListing('financial_periods'))->toBe([
        'id', 'enterprise_id', 'name', 'period_start', 'period_end', 'status', 'created_at', 'updated_at',
    ]);
});
