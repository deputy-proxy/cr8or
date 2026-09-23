<?php

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates a transaction with its enterprise, account, category and period relationships', function () {
    $enterprise = Enterprise::factory()->create();
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);

    $transaction = Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
        'financial_period_id' => $period,
        'amount' => '1234.5678',
        'transaction_date' => '2026-09-23',
    ]);

    expect($transaction->enterprise->is($enterprise))->toBeTrue()
        ->and($transaction->financialAccount->is($account))->toBeTrue()
        ->and($transaction->category->is($category))->toBeTrue()
        ->and($transaction->financialPeriod->is($period))->toBeTrue()
        ->and($transaction->amount)->toBe('1234.5678')
        ->and($transaction->transaction_date->equalTo(Carbon::parse('2026-09-23')))->toBeTrue();
});

it('preserves fixed precision financial amounts without floating point conversion', function () {
    $transaction = Transaction::factory()->create(['amount' => '999999999999.9999']);

    expect($transaction->amount)->toBe('999999999999.9999')
        ->and($transaction->getRawOriginal('amount'))->toBe('999999999999.9999');
});

it('rejects a transaction account from another enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignAccount = FinancialAccount::factory()->create();

    expect(fn () => Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_account_id' => $foreignAccount,
    ]))->toThrow(LogicException::class);
});

it('rejects a transaction category from another enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignCategory = TransactionCategory::factory()->create();

    expect(fn () => Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'transaction_category_id' => $foreignCategory,
    ]))->toThrow(LogicException::class);
});

it('rejects a transaction period from another enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignPeriod = FinancialPeriod::factory()->create();

    expect(fn () => Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_period_id' => $foreignPeriod,
    ]))->toThrow(LogicException::class);
});

it('prevents transaction scope relationships from being reassigned', function () {
    $transaction = Transaction::factory()->create();
    $transaction->financial_account_id = FinancialAccount::factory()->create()->id;

    expect(fn () => $transaction->save())->toThrow(LogicException::class);

    $transaction->refresh();
    $transaction->financial_period_id = FinancialPeriod::factory()->create(['enterprise_id' => $transaction->enterprise_id])->id;

    expect(fn () => $transaction->save())->toThrow(LogicException::class);
});

it('keeps unrelated transaction updates from changing historical scope', function () {
    $transaction = Transaction::factory()->create([
        'description' => 'Original description',
    ]);

    $enterpriseId = $transaction->enterprise_id;
    $accountId = $transaction->financial_account_id;
    $categoryId = $transaction->transaction_category_id;
    $periodId = $transaction->financial_period_id;

    $transaction->update(['description' => 'Updated description']);
    $transaction->refresh();

    expect($transaction->description)->toBe('Updated description')
        ->and($transaction->enterprise_id)->toBe($enterpriseId)
        ->and($transaction->financial_account_id)->toBe($accountId)
        ->and($transaction->transaction_category_id)->toBe($categoryId)
        ->and($transaction->financial_period_id)->toBe($periodId);
});

it('keeps the transaction schema focused on the authoritative ledger foundation', function () {
    expect(Schema::getColumnListing('transactions'))->toBe([
        'id', 'enterprise_id', 'financial_account_id', 'transaction_category_id',
        'amount', 'transaction_date', 'description', 'reference', 'created_at', 'updated_at', 'financial_period_id',
    ]);
});
