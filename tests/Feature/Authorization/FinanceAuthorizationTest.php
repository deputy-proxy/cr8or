<?php

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('enforces organization authorization for financial accounts, categories and transactions', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $owner,
        'organization_id' => $organization,
    ]);
    Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $organization,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);

    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $foreignAccount = FinancialAccount::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $category = TransactionCategory::factory()->create(['enterprise_id' => $enterprise]);
    $foreignCategory = TransactionCategory::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $transaction = Transaction::factory()->create([
        'enterprise_id' => $enterprise,
        'financial_account_id' => $account,
        'transaction_category_id' => $category,
    ]);
    $foreignTransaction = Transaction::factory()->create([
        'enterprise_id' => $foreignEnterprise,
        'financial_account_id' => $foreignAccount,
        'transaction_category_id' => $foreignCategory,
    ]);

    foreach ([$account, $category, $transaction] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($member)->allows('delete', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('view', $record->fresh()))->toBeTrue();
    }

    foreach ([$foreignAccount, $foreignCategory, $foreignTransaction] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeFalse();
    }

    expect(Gate::forUser($owner)->allows('createForEnterprise', [FinancialAccount::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [FinancialAccount::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [TransactionCategory::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [TransactionCategory::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [Transaction::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [Transaction::class, $enterprise]))->toBeFalse();
});