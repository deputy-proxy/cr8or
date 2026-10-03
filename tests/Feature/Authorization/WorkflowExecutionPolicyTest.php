<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkflowExecution;
use Illuminate\Support\Facades\Gate;

it('authorizes workflow execution access by organization membership', function (): void {
    $member = User::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreign = User::factory()->create();

    $execution = WorkflowExecution::factory()->create([
        'actor_id' => $member->id,
    ]);

    $enterprise = $execution->workflow->enterprise;

    Membership::factory()->owner()->create([
        'user_id' => $member,
        'organization_id' => $enterprise->organization_id,
    ]);
    Membership::factory()->owner()->create([
        'user_id' => $foreign,
        'organization_id' => $foreignEnterprise->organization_id,
    ]);

    expect(Gate::forUser($member)->allows('view', $execution))->toBeTrue()
        ->and(Gate::forUser($member)->allows('resume', $execution))->toBeTrue()
        ->and(Gate::forUser($foreign)->allows('view', $execution))->toBeFalse()
        ->and(Gate::forUser($foreign)->allows('resume', $execution))->toBeFalse();
});
