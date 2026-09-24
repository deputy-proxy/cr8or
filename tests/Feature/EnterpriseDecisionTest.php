<?php

use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('records a decision with its enterprise, actor and decision timestamp', function () {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create(['name' => 'Decision Actor']);
    $decidedAt = Carbon::parse('2026-09-21 18:30:00');

    $decision = EnterpriseDecision::factory()->by($actor)->create([
        'enterprise_id' => $enterprise,
        'title' => 'Enter the Romanian SME market',
        'summary' => 'Launch the first commercial offering for Romanian SMEs.',
        'rationale' => 'The enterprise has validated demand in this segment.',
        'decided_at' => $decidedAt,
    ]);

    $decision->refresh();

    expect($decision->enterprise->is($enterprise))->toBeTrue()
        ->and($decision->actor->is($actor))->toBeTrue()
        ->and($decision->actor_name)->toBe('Decision Actor')
        ->and($decision->decided_at->equalTo($decidedAt))->toBeTrue();
});

it('preserves required historical fields when a decision is updated', function () {
    $actor = User::factory()->create(['name' => 'Historical Actor']);
    $decidedAt = Carbon::parse('2026-09-20 10:15:00');
    $decision = EnterpriseDecision::factory()->by($actor)->create([
        'decided_at' => $decidedAt,
        'title' => 'Original decision',
        'summary' => 'Original summary',
        'rationale' => 'Original rationale',
    ]);

    $decision->update(['title' => 'Clarified decision', 'summary' => 'Clarified summary']);
    $decision->refresh();

    expect($decision->actor_id)->toBe($actor->id)
        ->and($decision->actor_name)->toBe('Historical Actor')
        ->and($decision->decided_at->equalTo($decidedAt))->toBeTrue()
        ->and($decision->rationale)->toBe('Original rationale');
});

it('enforces decision authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $decision = EnterpriseDecision::factory()->create(['enterprise_id' => $enterprise]);
    $foreignDecision = EnterpriseDecision::factory()->create([
        'enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization]),
    ]);

    expect(Gate::forUser($owner)->allows('view', $decision))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $decision))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $decision))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $decision))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $decision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignDecision))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('createForEnterprise', [EnterpriseDecision::class, $enterprise]))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createForEnterprise', [EnterpriseDecision::class, $enterprise]))->toBeFalse();
});

it('keeps the decision schema focused on historical decision records', function () {
    expect(Schema::getColumnListing('enterprise_decisions'))->toBe([
        'id', 'enterprise_id', 'actor_id', 'actor_name', 'title', 'summary', 'rationale', 'decided_at', 'created_at', 'updated_at',
    ]);
});
