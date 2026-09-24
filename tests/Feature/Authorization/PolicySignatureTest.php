<?php

it('keeps standard policy method signatures compatible with Laravel Gate', function () {
    foreach (glob(app_path('Policies/*.php')) as $file) {
        $class = 'App\\Policies\\'.pathinfo($file, PATHINFO_FILENAME);
        $reflection = new ReflectionClass($class);

        foreach (['viewAny', 'create'] as $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }

            expect($reflection->getMethod($method)->getNumberOfParameters())
                ->toBe($method === 'viewAny' ? 1 : 1);
        }

        foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }

            expect($reflection->getMethod($method)->getNumberOfParameters())
                ->toBe(2);
        }
    }
});

it('keeps parent context in explicit custom authorization methods', function () {
    expect(method_exists(App\Policies\EnterprisePolicy::class, 'createForOrganization'))->toBeTrue()
        ->and(method_exists(App\Policies\MembershipPolicy::class, 'createForOrganization'))->toBeTrue()
        ->and(method_exists(App\Policies\AgentAssignmentPolicy::class, 'createForAgentAssignment'))->toBeTrue()
        ->and(method_exists(App\Policies\AgentPermissionPolicy::class, 'createForAgentAssignment'))->toBeTrue()
        ->and(method_exists(App\Policies\AgentPermissionPolicy::class, 'updateForAgentAssignment'))->toBeTrue()
        ->and(method_exists(App\Policies\ContentSeriesPolicy::class, 'createForCampaign'))->toBeTrue()
        ->and(method_exists(App\Policies\StrategyPolicy::class, 'createForObjective'))->toBeTrue()
        ->and(method_exists(App\Policies\PlanPolicy::class, 'createForStrategy'))->toBeTrue()
        ->and(method_exists(App\Policies\InitiativePolicy::class, 'createForPlan'))->toBeTrue()
        ->and(method_exists(App\Policies\ScriptPolicy::class, 'createForContentItem'))->toBeTrue();
});
