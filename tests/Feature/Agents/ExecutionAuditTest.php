<?php

use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApprovalRequestService;

it('preserves correlation and provider references on the existing execution record', function () {
    $execution = AgentExecution::factory()->create([
        'correlation_id' => 'audit-123',
        'provider' => 'fake',
        'external_execution_id' => 'provider-run-123',
    ]);

    expect($execution->refresh()->correlation_id)->toBe('audit-123')
        ->and($execution->provider)->toBe('fake')
        ->and($execution->external_execution_id)->toBe('provider-run-123');
});

it('preserves the correlation identifier in approval historical context', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create();

    $approval = app(ApprovalRequestService::class)->request(
        $actor,
        'work.update',
        $assignment,
        null,
        ['work_item_id' => 123],
        'audit-approval-123',
    );

    expect($approval->correlation_id)->toBe('audit-approval-123')
        ->and($approval->refresh()->correlation_id)->toBe('audit-approval-123');
});
