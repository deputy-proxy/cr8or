<?php

use App\Models\BusinessHealthResult;
use App\Models\Enterprise;
use App\Models\FinancialAccount;
use App\Models\FinancialPeriod;
use App\Models\FinancialReport;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

it('enforces enterprise authorization for reports and health results', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreign = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);
    $period = FinancialPeriod::factory()->create(['enterprise_id' => $enterprise]);
    $foreignPeriod = FinancialPeriod::factory()->create(['enterprise_id' => $foreign]);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $report = FinancialReport::factory()->create(['enterprise_id' => $enterprise, 'financial_period_id' => $period, 'financial_account_id' => $account]);
    $foreignReport = FinancialReport::factory()->create(['enterprise_id' => $foreign, 'financial_period_id' => $foreignPeriod]);
    $health = BusinessHealthResult::factory()->create(['enterprise_id' => $enterprise, 'financial_report_id' => $report]);
    $foreignHealth = BusinessHealthResult::factory()->create(['enterprise_id' => $foreign, 'financial_report_id' => $foreignReport]);

    expect(Gate::forUser($owner)->allows('view', $report))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $report))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $foreignReport))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $health))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $foreignHealth))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [FinancialReport::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [FinancialReport::class, $enterprise]))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('create', [BusinessHealthResult::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('create', [BusinessHealthResult::class, $enterprise]))->toBeFalse();
});

it('keeps Agent financial context inside the authorized enterprise boundary', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreign = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);
    $account = FinancialAccount::factory()->create(['enterprise_id' => $enterprise]);
    $foreignAccount = FinancialAccount::factory()->create(['enterprise_id' => $foreign]);

    $context = app(McpContextAssembler::class)->financial($owner, $enterprise->getKey());

    expect($context['enterprise']['id'])->toBe($enterprise->getKey())
        ->and(collect($context['accounts'])->pluck('id'))->toContain($account->getKey())
        ->not->toContain($foreignAccount->getKey());

    expect(fn () => app(McpContextAssembler::class)->financial($owner, $foreign->getKey()))
        ->toThrow(AuthorizationException::class);
});