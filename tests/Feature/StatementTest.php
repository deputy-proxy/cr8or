<?php

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\Statement;
use App\Models\StatementEntry;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use LogicException;

it('creates a statement attributed to its organization, enterprise and financial account', function () {
    $enterprise = Enterprise::factory()->create();
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);

    $statement = Statement::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise,
        'financial_account_id' => $account,
    ]);

    expect($statement->organization_id)->toBe($enterprise->organization_id)
        ->and($statement->enterprise->is($enterprise))->toBeTrue()
        ->and($statement->financialAccount->is($account))->toBeTrue();
});

it('rejects statements whose account belongs to another enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignAccount = FinancialAccount::factory()->create();

    expect(fn () => Statement::factory()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise,
        'financial_account_id' => $foreignAccount,
    ]))->toThrow(LogicException::class);
});

it('rejects statements whose enterprise belongs to another organization', function () {
    $enterprise = Enterprise::factory()->create();
    $foreignOrganization = Enterprise::factory()->create();

    expect(fn () => Statement::factory()->create([
        'organization_id' => $foreignOrganization->organization_id,
        'enterprise_id' => $enterprise,
    ]))->toThrow(LogicException::class);
});

it('prevents duplicate statement source references within an organization', function () {
    $statement = Statement::factory()->create();

    expect(fn () => Statement::factory()->create([
        'organization_id' => $statement->organization_id,
        'enterprise_id' => $statement->enterprise_id,
        'financial_account_id' => $statement->financial_account_id,
        'source' => $statement->source,
        'source_reference' => $statement->source_reference,
    ]))->toThrow(QueryException::class);
});

it('preserves statement source identity after creation', function () {
    $statement = Statement::factory()->create();
    $statement->source_reference = 'changed';

    expect(fn () => $statement->save())->toThrow(LogicException::class);
});

it('validates statement periods', function () {
    expect(fn () => Statement::factory()->create([
        'period_start' => '2026-09-30',
        'period_end' => '2026-09-01',
    ]))->toThrow(LogicException::class);
});

it('creates statement entries that remain attributable to the statement account and enterprise', function () {
    $statement = Statement::factory()->create();
    $entry = StatementEntry::factory()->create(['statement_id' => $statement]);

    expect($entry->organization_id)->toBe($statement->organization_id)
        ->and($entry->enterprise_id)->toBe($statement->enterprise_id)
        ->and($entry->financial_account_id)->toBe($statement->financial_account_id)
        ->and($entry->statement->is($statement))->toBeTrue();
});

it('rejects statement entries from another statement scope', function () {
    $statement = Statement::factory()->create();
    $foreignStatement = Statement::factory()->create();

    expect(fn () => StatementEntry::factory()->create([
        'statement_id' => $statement,
        'organization_id' => $foreignStatement->organization_id,
        'enterprise_id' => $foreignStatement->enterprise_id,
        'financial_account_id' => $foreignStatement->financial_account_id,
    ]))->toThrow(LogicException::class);
});

it('links statement entries to authoritative transactions without changing transaction history', function () {
    $statement = Statement::factory()->create();
    $category = TransactionCategory::factory()->create(['enterprise_id' => $statement->enterprise_id]);
    $transaction = Transaction::factory()->create([
        'enterprise_id' => $statement->enterprise_id,
        'financial_account_id' => $statement->financial_account_id,
        'transaction_category_id' => $category,
        'description' => 'Authoritative transaction',
    ]);

    $entry = StatementEntry::factory()->create([
        'statement_id' => $statement,
        'transaction_id' => $transaction,
    ]);

    expect($entry->transaction->is($transaction))->toBeTrue()
        ->and($transaction->fresh()->description)->toBe('Authoritative transaction');
});

it('rejects links to transactions from another enterprise or account', function () {
    $statement = Statement::factory()->create();
    $foreignTransaction = Transaction::factory()->create();

    expect(fn () => StatementEntry::factory()->create([
        'statement_id' => $statement,
        'transaction_id' => $foreignTransaction,
    ]))->toThrow(LogicException::class);
});

it('prevents duplicate imported statement entry source references', function () {
    $entry = StatementEntry::factory()->create();

    expect(fn () => StatementEntry::factory()->create([
        'organization_id' => $entry->organization_id,
        'enterprise_id' => $entry->enterprise_id,
        'statement_id' => $entry->statement_id,
        'financial_account_id' => $entry->financial_account_id,
        'source' => $entry->source,
        'source_reference' => $entry->source_reference,
    ]))->toThrow(QueryException::class);
});

it('keeps source references separate from authoritative transaction state', function () {
    $statement = Statement::factory()->create();
    $entry = StatementEntry::factory()->create(['statement_id' => $statement]);
    $entry->description = 'Source description changed';

    $entry->save();

    expect($entry->fresh()->description)->toBe('Source description changed')
        ->and($entry->fresh()->transaction_id)->toBeNull();
});

it('prevents statement entry scope reassignment', function () {
    $entry = StatementEntry::factory()->create();
    $entry->enterprise_id = Enterprise::factory()->create()->id;

    expect(fn () => $entry->save())->toThrow(LogicException::class);
});

it('keeps the statement schema focused on source-record requirements', function () {
    expect(Schema::getColumnListing('statements'))->toBe([
        'id', 'organization_id', 'enterprise_id', 'financial_account_id', 'source',
        'source_reference', 'statement_date', 'period_start', 'period_end', 'metadata',
        'created_at', 'updated_at',
    ]);
});

it('keeps the statement entry schema focused on import-record requirements', function () {
    expect(Schema::getColumnListing('statement_entries'))->toBe([
        'id', 'organization_id', 'enterprise_id', 'statement_id', 'financial_account_id',
        'transaction_id', 'source', 'source_reference', 'amount', 'entry_date',
        'description', 'reference', 'metadata', 'created_at', 'updated_at',
    ]);
});
