<?php

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates a financial account within an enterprise', function () {
    $enterprise = Enterprise::factory()->create();

    $account = FinancialAccount::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Operating Account',
        'type' => 'bank',
        'currency' => 'EUR',
    ]);

    expect($account->enterprise->is($enterprise))->toBeTrue()
        ->and($account->name)->toBe('Operating Account')
        ->and($account->currency)->toBe('EUR')
        ->and($enterprise->financialAccounts()->whereKey($account)->exists())->toBeTrue();
});

it('rejects duplicate account names within an enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    FinancialAccount::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Operating Account']);

    expect(fn () => FinancialAccount::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Operating Account',
    ]))->toThrow(QueryException::class);
});

it('allows the same account name in different enterprises', function () {
    $enterprises = Enterprise::factory()->count(2)->create();

    FinancialAccount::factory()->create(['enterprise_id' => $enterprises[0], 'name' => 'Operating Account']);
    FinancialAccount::factory()->create(['enterprise_id' => $enterprises[1], 'name' => 'Operating Account']);

    expect(FinancialAccount::query()->where('name', 'Operating Account')->count())->toBe(2);
});

it('prevents financial account enterprise reassignment', function () {
    $account = FinancialAccount::factory()->create();
    $account->enterprise_id = Enterprise::factory()->create()->id;

    expect(fn () => $account->save())->toThrow(LogicException::class);
});

it('keeps the financial account schema focused on current finance requirements', function () {
    expect(Schema::getColumnListing('financial_accounts'))->toBe([
        'id', 'enterprise_id', 'name', 'type', 'status', 'currency', 'created_at', 'updated_at',
    ]);
});
