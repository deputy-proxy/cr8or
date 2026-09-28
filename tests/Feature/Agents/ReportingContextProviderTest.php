<?php

use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentContextBuilder;
use App\Services\BusinessPerformanceReportingService;
use Illuminate\Support\Carbon;

it('provides authorized latest reporting context to Agents without recalculating state', function () {
    $organization = App\Models\Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    App\Models\Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    app(BusinessPerformanceReportingService::class)->generate(
        $user,
        $enterprise,
        Carbon::parse('2026-09-01T00:00:00Z'),
        Carbon::parse('2026-09-30T23:59:59Z'),
    );

    $context = app(AgentContextBuilder::class)->build($user, $enterprise, ['reporting']);

    expect($context->has('reporting'))->toBeTrue()
        ->and($context->section('reporting')?->data['report']['type'])
        ->toBe(BusinessPerformanceReportingService::REPORT_TYPE);
});