<?php

use App\Models\Budget;
use App\Models\Enterprise;
use App\Models\FinancialPeriod;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('enforces organization authorization for budgets and financial periods', function () {
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
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $foreignPeriod = FinancialPeriod::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $budget = Budget::factory()->create(['enterprise_id' => $enterprise, 'financial_period_id' => $period]);
    $foreignBudget = Budget::factory()->create(['enterprise_id' => $foreignEnterprise, 'financial_period_id' => $foreignPeriod]);

    foreach ([$period, $budget] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('view', $record))->toBeTrue()
            ->and(Gate::forUser($member)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($member)->allows('delete', $record))->toBeFalse();
    }

    foreach ([$foreignPeriod, $foreignBudget] as $record) {
        expect(Gate::forUser($owner)->allows('view', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('update', $record))->toBeFalse()
            ->and(Gate::forUser($owner)->allows('delete', $record))->toBeFalse();
    }

    expect(Gate::forUser($owner)->allows('create', [FinancialPeriod::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [FinancialPeriod::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [Budget::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [Budget::class, $enterprise]))->toBeFalse();
});
