<?php

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\Revenue;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates revenue linked to an authoritative transaction', function () {
    $enterprise = Enterprise::factory()->create();
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_category_id' => $category]);

    $revenue = Revenue::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_id' => $transaction, 'amount' => '1234.5678']);

    expect($revenue->enterprise->is($enterprise))->toBeTrue()
        ->and($revenue->financialAccount->is($account))->toBeTrue()
        ->and($revenue->transaction->is($transaction))->toBeTrue()
        ->and($revenue->amount)->toBe('1234.5678');
});

it('allows revenue without an underlying transaction', function () {
    expect(Revenue::factory()->create()->transaction_id)->toBeNull();
});

it('rejects foreign account and transaction relationships', function () {
    $enterprise = Enterprise::factory()->create();
    expect(fn () => Revenue::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => FinancialAccount::factory()->create()]))->toThrow(LogicException::class);
    expect(fn () => Revenue::factory()->create(['enterprise_id' => $enterprise, 'transaction_id' => Transaction::factory()->create()]))->toThrow(LogicException::class);
});

it('rejects an account that does not match its transaction', function () {
    $enterprise = Enterprise::factory()->create();
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $otherAccount = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_category_id' => $category]);

    expect(fn () => Revenue::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $otherAccount, 'transaction_id' => $transaction]))->toThrow(LogicException::class);
});

it('prevents revenue enterprise reassignment and negative amounts', function () {
    $revenue = Revenue::factory()->create();
    $revenue->enterprise_id = Enterprise::factory()->create()->id;
    expect(fn () => $revenue->save())->toThrow(LogicException::class);
    expect(fn () => Revenue::factory()->create(['amount' => '-1.0000']))->toThrow(LogicException::class);
});

it('keeps exact decimal revenue amounts', function () {
    $revenue = Revenue::factory()->create(['amount' => '999999999999.9999']);
    expect($revenue->amount)->toBe('999999999999.9999')->and($revenue->getRawOriginal('amount'))->toBe('999999999999.9999');
});

it('keeps the revenue schema focused on current requirements', function () {
    expect(Schema::getColumnListing('revenues'))->toBe([
        'id', 'enterprise_id', 'financial_account_id', 'transaction_id', 'amount', 'currency', 'revenue_date',
        'source', 'reference', 'description', 'created_at', 'updated_at',
    ]);
});
