<?php

use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\FinancialReport;
use App\Models\Membership;
use App\Models\Revenue;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\FinancialReportingService;
use RuntimeException;

it('generates a period, account and category scoped report without changing source data', function () {
    $enterprise = Enterprise::factory()->create(['name' => 'Original Enterprise']);
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise, 'currency' => 'EUR', 'name' => 'Operating EUR']);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create([
        'enterprise_id' => $enterprise, 'financial_account_id' => $account,
        'transaction_category_id' => $category, 'financial_period_id' => $period,
        'amount' => '125.5000', 'transaction_date' => '2026-09-10',
    ]);
    $revenue = Revenue::factory()->create([
        'enterprise_id' => $enterprise, 'financial_account_id' => $account,
        'financial_period_id' => $period, 'transaction_id' => $transaction,
        'amount' => '1000.2500', 'currency' => 'EUR', 'revenue_date' => '2026-09-12',
    ]);
    Expense::factory()->create([
        'enterprise_id' => $enterprise, 'financial_account_id' => $account,
        'transaction_category_id' => $category, 'financial_period_id' => $period,
        'amount' => '300.1250', 'currency' => 'EUR', 'expense_date' => '2026-09-13',
    ]);

    $report = app(FinancialReportingService::class)->generate($user, $enterprise, $period, $account, $category);

    expect($report->metrics)->toMatchArray([
        'revenue_total' => '1000.2500',
        'expense_total' => '300.1250',
        'net_movement' => '700.1250',
        'transaction_net_movement' => '125.5000',
        'transaction_count' => 1,
        'account_count' => 1,
        'budget_total' => '0.0000',
        'budget_variance' => '-300.1250',
        'budget_count' => 0,
    ]);

    $enterprise->update(['name' => 'Changed Enterprise']);
    $account->update(['name' => 'Renamed Account']);
    $report->refresh();
    $revenue->refresh();

    expect($report->source_snapshot['enterprise']['name'])->toBe('Original Enterprise')
        ->and($report->source_snapshot['account']['name'])->toBe('Operating EUR')
        ->and($revenue->amount)->toBe('1000.2500');
});

it('rejects cross enterprise reporting scopes and persists nothing', function () {
    $enterprise = Enterprise::factory()->create();
    $foreign = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $foreignPeriod = FinancialPeriod::factory()->create(['enterprise_id' => $foreign]);
    $foreignAccount = FinancialAccount::factory()->create(['enterprise_id' => $foreign]);
    $foreignCategory = TransactionCategory::factory()->create(['enterprise_id' => $foreign]);

    expect(fn () => app(FinancialReportingService::class)->generate($user, $enterprise, $foreignPeriod))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(FinancialReportingService::class)->generate($user, $enterprise, $period, $foreignAccount))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(FinancialReportingService::class)->generate($user, $enterprise, $period, null, $foreignCategory))
        ->toThrow(RuntimeException::class);
    expect(FinancialReport::query()->count())->toBe(0);
});

it('does not persist a report for an ambiguous multi currency enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    FinancialAccount::factory()->create(['enterprise_id' => $enterprise, 'currency' => 'EUR']);
    FinancialAccount::factory()->create(['enterprise_id' => $enterprise, 'currency' => 'USD']);

    expect(fn () => app(FinancialReportingService::class)->generate($user, $enterprise, $period))
        ->toThrow(RuntimeException::class, 'A report without an account scope requires exactly one Enterprise currency.');
    expect(FinancialReport::query()->count())->toBe(0);
});