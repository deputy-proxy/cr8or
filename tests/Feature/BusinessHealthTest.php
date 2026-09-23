<?php

use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\Membership;
use App\Models\Revenue;
use App\Models\User;
use App\Services\FinancialReportingService;

it('creates a business health result from a successful financial report', function () {
    $enterprise = Enterprise::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise, 'currency' => 'EUR']);

    Revenue::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'financial_period_id' => $period, 'amount' => '1000.0000', 'currency' => 'EUR', 'revenue_date' => '2026-09-10']);
    Expense::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'financial_period_id' => $period, 'amount' => '250.0000', 'currency' => 'EUR', 'expense_date' => '2026-09-11']);

    $report = app(FinancialReportingService::class)->generate($user, $enterprise, $period, $account);
    $result = BusinessHealthResult::query()->where('financial_report_id', $report->getKey())->firstOrFail();

    expect($result->health_status)->toBe('healthy')
        ->and($result->metrics['net_movement'])->toBe('750.0000')
        ->and($result->source_snapshot)->toBe($report->source_snapshot);
});
