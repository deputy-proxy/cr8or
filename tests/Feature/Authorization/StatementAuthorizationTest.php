<?php

use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Statement;
use App\Models\StatementEntry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('enforces organization authorization for statements and statement entries', function () {
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
    $statement = Statement::factory()->create([
        'organization_id' => $organization,
        'enterprise_id' => $enterprise,
        'financial_account_id' => $account,
    ]);
    $foreignStatement = Statement::factory()->create([
        'organization_id' => $foreignOrganization,
        'enterprise_id' => $foreignEnterprise,
        'financial_account_id' => $foreignAccount,
    ]);
    $entry = StatementEntry::factory()->create(['statement_id' => $statement]);
    $foreignEntry = StatementEntry::factory()->create(['statement_id' => $foreignStatement]);

    foreach ([$statement, $entry] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($member)->allows('delete', $record))->toBeFalse();
    }

    foreach ([$foreignStatement, $foreignEntry] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeFalse();
    }

    expect(Gate::forUser($owner)->allows('create', [Statement::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Statement::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [StatementEntry::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [StatementEntry::class, $enterprise]))->toBeFalse();
});