<?php

use App\Models\Budget;
use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates a budget within its enterprise and financial period', function () {
    $enterprise = Enterprise::factory()->create();
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);

    $budget = Budget::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_period_id' => $period,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
        'planned_amount' => '1234.5678',
    ]);

    expect($budget->enterprise->is($enterprise))->toBeTrue()
        ->and($budget->financialPeriod->is($period))->toBeTrue()
        ->and($budget->financialAccount->is($account))->toBeTrue()
        ->and($budget->category->is($category))->toBeTrue()
        ->and($budget->planned_amount)->toBe('1234.5678');
});

it('rejects foreign enterprise period, account and category relationships', function () {
    $enterprise = Enterprise::factory()->create();

    expect(fn () => Budget::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_period_id' => FinancialPeriod::factory()->create(),
    ]))->toThrow(LogicException::class);

    expect(fn () => Budget::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_account_id' => FinancialAccount::factory()->create(),
    ]))->toThrow(LogicException::class);

    expect(fn () => Budget::factory()->create([
        'enterprise_id' => $enterprise,
        'transaction_category_id' => TransactionCategory::factory()->create(),
    ]))->toThrow(LogicException::class);
});

it('preserves budget historical scope when the budget is updated', function () {
    $enterprise = Enterprise::factory()->create();
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $budget = Budget::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_period_id' => $period,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
        'planned_amount' => '100.0000',
    ]);

    $budget->planned_amount = '250.0000';
    $budget->financial_period_id = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise])->id;
    $budget->financial_account_id = FinancialAccount::factory()->create(['enterprise_id' => $enterprise])->id;
    $budget->transaction_category_id = TransactionCategory::factory()->create(['enterprise_id' => $enterprise])->id;
    $budget->save();
    $budget->refresh();

    expect($budget->planned_amount)->toBe('250.0000')
        ->and($budget->financial_period_id)->toBe($period->id)
        ->and($budget->financial_account_id)->toBe($account->id)
        ->and($budget->transaction_category_id)->toBe($category->id);
});

it('does not mutate authoritative transactions when a budget changes', function () {
    $enterprise = Enterprise::factory()->create();
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
        'financial_period_id' => $period,
        'amount' => '999.0000',
    ]);

    $budget = Budget::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_period_id' => $period,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
        'planned_amount' => '500.0000',
    ]);

    $budget->update(['planned_amount' => '750.0000']);
    $transaction->refresh();

    expect($transaction->amount)->toBe('999.0000')
        ->and($transaction->financial_period_id)->toBe($period->id);
});

it('keeps the budget schema focused on planning records', function () {
    expect(Schema::getColumnListing('budgets'))->toBe([
        'id', 'enterprise_id', 'financial_period_id', 'financial_account_id', 'transaction_category_id',
        'name', 'planned_amount', 'currency', 'description', 'created_at', 'updated_at',
    ]);
});
