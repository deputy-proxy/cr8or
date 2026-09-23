<?php

use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates expense with account, transaction and category relationships', function () {
    $enterprise = Enterprise::factory()->create();
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_category_id' => $category]);

    $expense = Expense::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_id' => $transaction, 'transaction_category_id' => $category, 'amount' => '321.9876']);

    expect($expense->enterprise->is($enterprise))->toBeTrue()
        ->and($expense->financialAccount->is($account))->toBeTrue()
        ->and($expense->transaction->is($transaction))->toBeTrue()
        ->and($expense->category->is($category))->toBeTrue()
        ->and($expense->amount)->toBe('321.9876');
});

it('allows expense without ledger relationships', function () {
    $expense = Expense::factory()->create();
    expect($expense->financial_account_id)->toBeNull()->and($expense->transaction_id)->toBeNull()->and($expense->transaction_category_id)->toBeNull();
});

it('rejects foreign account, transaction and category relationships', function () {
    $enterprise = Enterprise::factory()->create();
    expect(fn () => Expense::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => FinancialAccount::factory()->create()]))->toThrow(LogicException::class);
    expect(fn () => Expense::factory()->create(['enterprise_id' => $enterprise, 'transaction_id' => Transaction::factory()->create()]))->toThrow(LogicException::class);
    expect(fn () => Expense::factory()->create(['enterprise_id' => $enterprise, 'transaction_category_id' => TransactionCategory::factory()->create()]))->toThrow(LogicException::class);
});

it('rejects expense relationships that disagree with its transaction', function () {
    $enterprise = Enterprise::factory()->create();
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $otherAccount = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $otherCategory = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_category_id' => $category]);

    expect(fn () => Expense::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $otherAccount, 'transaction_id' => $transaction]))->toThrow(LogicException::class);
    expect(fn () => Expense::factory()->create(['enterprise_id' => $enterprise, 'transaction_category_id' => $otherCategory, 'transaction_id' => $transaction]))->toThrow(LogicException::class);
});

it('prevents expense enterprise reassignment and negative amounts', function () {
    $expense = Expense::factory()->create();
    $expense->enterprise_id = Enterprise::factory()->create()->id;
    expect(fn () => $expense->save())->toThrow(LogicException::class);
    expect(fn () => Expense::factory()->create(['amount' => '-1.0000']))->toThrow(LogicException::class);
});

it('keeps exact decimal expense amounts', function () {
    $expense = Expense::factory()->create(['amount' => '999999999999.9999']);
    expect($expense->amount)->toBe('999999999999.9999')->and($expense->getRawOriginal('amount'))->toBe('999999999999.9999');
});

it('keeps the expense schema focused on current requirements', function () {
    expect(Schema::getColumnListing('expenses'))->toBe([
        'id', 'enterprise_id', 'financial_account_id', 'transaction_id', 'transaction_category_id', 'amount',
        'currency', 'expense_date', 'source', 'reference', 'description', 'created_at', 'updated_at',
    ]);
});