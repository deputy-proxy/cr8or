<?php

use App\Models\Customer;
use App\Models\Enterprise;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Revenue;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('enforces organization authorization for invoices revenue and expenses', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);

    $customer = Customer::factory()->create(['enterprise_id' => $enterprise]);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $transaction = Transaction::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_category_id' => $category]);

    $invoice = Invoice::factory()->create(['enterprise_id' => $enterprise, 'customer_id' => $customer]);
    $revenue = Revenue::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_id' => $transaction]);
    $expense = Expense::factory()->create(['enterprise_id' => $enterprise, 'financial_account_id' => $account, 'transaction_id' => $transaction, 'transaction_category_id' => $category]);

    $foreignAccount = FinancialAccount::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $foreignCategory = TransactionCategory::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $foreignTransaction = Transaction::factory()->create(['enterprise_id' => $foreignEnterprise, 'financial_account_id' => $foreignAccount, 'transaction_category_id' => $foreignCategory]);
    $foreignInvoice = Invoice::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $foreignRevenue = Revenue::factory()->create(['enterprise_id' => $foreignEnterprise, 'financial_account_id' => $foreignAccount, 'transaction_id' => $foreignTransaction]);
    $foreignExpense = Expense::factory()->create(['enterprise_id' => $foreignEnterprise, 'financial_account_id' => $foreignAccount, 'transaction_id' => $foreignTransaction, 'transaction_category_id' => $foreignCategory]);

    foreach ([$invoice, $revenue, $expense] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($member)->allows('delete', $record))->toBeFalse();
    }

    foreach ([$foreignInvoice, $foreignRevenue, $foreignExpense] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeFalse();
    }

    expect(Gate::forUser($owner)->allows('createForEnterprise', [Invoice::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Invoice::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Revenue::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Revenue::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Expense::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Expense::class, $enterprise]))->toBeFalse();
});