<?php

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\BusinessHealthResults\BusinessHealthResultResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FinancialAccounts\FinancialAccountResource;
use App\Filament\Resources\FinancialPeriods\FinancialPeriodResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Revenues\RevenueResource;
use App\Filament\Resources\StatementEntries\StatementEntryResource;
use App\Filament\Resources\Statements\StatementResource;
use App\Filament\Resources\TransactionCategories\TransactionCategoryResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Budget;
use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\FinancialReport;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Revenue;
use App\Models\Statement;
use App\Models\StatementEntry;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('scopes phase 6 resources to the authenticated organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $foreignAccount = FinancialAccount::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $foreignCategory = TransactionCategory::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $foreignPeriod = FinancialPeriod::factory()->create(['enterprise_id' => $foreignEnterprise]);

    $transaction = Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
        'financial_period_id' => $period,
    ]);
    $foreignTransaction = Transaction::factory()->create([
        'enterprise_id' => $foreignEnterprise,
        'financial_account_id' => $foreignAccount,
        'transaction_category_id' => $foreignCategory,
        'financial_period_id' => $foreignPeriod,
    ]);

    $statement = Statement::factory()->create(['enterprise_id' => $enterprise, 'organization_id' => $organization, 'financial_account_id' => $account]);
    $foreignStatement = Statement::factory()->create(['enterprise_id' => $foreignEnterprise, 'organization_id' => $otherOrganization, 'financial_account_id' => $foreignAccount]);
    $entry = StatementEntry::factory()->create(['statement_id' => $statement->id]);
    $foreignEntry = StatementEntry::factory()->create(['statement_id' => $foreignStatement->id]);

    $invoice = Invoice::factory()->create(['enterprise_id' => $enterprise]);
    $foreignInvoice = Invoice::factory()->create(['enterprise_id' => $foreignEnterprise]);

    $revenue = Revenue::factory()->create(['enterprise_id' => $enterprise]);
    $foreignRevenue = Revenue::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $expense = Expense::factory()->create(['enterprise_id' => $enterprise]);
    $foreignExpense = Expense::factory()->create(['enterprise_id' => $foreignEnterprise]);

    $budget = Budget::factory()->create(['enterprise_id' => $enterprise]);
    $foreignBudget = Budget::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $report = FinancialReport::factory()->create(['enterprise_id' => $enterprise]);
    $foreignReport = FinancialReport::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $health = BusinessHealthResult::factory()->create(['enterprise_id' => $enterprise, 'financial_report_id' => $report]);
    $foreignHealth = BusinessHealthResult::factory()->create(['enterprise_id' => $foreignEnterprise, 'financial_report_id' => $foreignReport]);

    $this->actingAs($user);

    foreach ([
        [FinancialAccountResource::class, $account->id, $foreignAccount->id],
        [TransactionCategoryResource::class, $category->id, $foreignCategory->id],
        [FinancialPeriodResource::class, $period->id, $foreignPeriod->id],
        [TransactionResource::class, $transaction->id, $foreignTransaction->id],
        [StatementResource::class, $statement->id, $foreignStatement->id],
        [StatementEntryResource::class, $entry->id, $foreignEntry->id],
        [InvoiceResource::class, $invoice->id, $foreignInvoice->id],
        [RevenueResource::class, $revenue->id, $foreignRevenue->id],
        [ExpenseResource::class, $expense->id, $foreignExpense->id],
        [BudgetResource::class, $budget->id, $foreignBudget->id],
        [FinancialReportResource::class, $report->id, $foreignReport->id],
        [BusinessHealthResultResource::class, $health->id, $foreignHealth->id],
    ] as [$resource, $visibleId, $hiddenId]) {
        expect($resource::getEloquentQuery()->pluck('id')->all())
            ->toContain($visibleId)
            ->not->toContain($hiddenId)
            ->and($resource::canViewAny())->toBeTrue();
    }
});

it('keeps derived finance results read-only', function () {
    expect(FinancialReportResource::canCreate())->toBeFalse()
        ->and(FinancialReportResource::getPages())->not->toHaveKey('create')
        ->and(FinancialReportResource::getPages())->not->toHaveKey('edit')
        ->and(BusinessHealthResultResource::canCreate())->toBeFalse()
        ->and(BusinessHealthResultResource::getPages())->not->toHaveKey('create')
        ->and(BusinessHealthResultResource::getPages())->not->toHaveKey('edit');
});

it('keeps historical finance relationships behind server-side policy authorization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $foreignAccount = FinancialAccount::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $invoice = Invoice::factory()->create(['enterprise_id' => $enterprise]);
    $foreignInvoice = Invoice::factory()->create(['enterprise_id' => $foreignEnterprise]);

    expect(Gate::forUser($owner)->allows('update', $account))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $foreignAccount))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $invoice))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $foreignInvoice))->toBeFalse();
});