<?php

use App\Filament\Pages\AgentCollaborationReport;
use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Filament\Resources\AgentDelegations\AgentDelegationResource;
use App\Filament\Resources\AgentDescriptors\AgentDescriptorResource;
use App\Filament\Resources\AgentExecutions\AgentExecutionResource;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Models\AgentDelegation;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('scopes delegation administration to permitted organizations', function () {
    $delegation = AgentDelegation::factory()->succeeded()->create();
    $foreignDelegation = AgentDelegation::factory()->succeeded()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $delegation->organization_id,
    ]);

    $this->actingAs($user);

    expect(AgentDelegationResource::canViewAny())->toBeTrue();
    expect(AgentDelegationResource::getEloquentQuery()->pluck('id')->all())->toContain($delegation->id);
    expect(AgentDelegationResource::getEloquentQuery()->pluck('id')->all())->not->toContain($foreignDelegation->id);
});

it('denies phase seven administration without organization membership', function () {
    $this->actingAs(User::factory()->create());

    expect(AgentDelegationResource::canViewAny())->toBeFalse();
    expect(AgentCollaborationReport::canAccess())->toBeFalse();
});

it('keeps historical records read-only', function () {
    expect(AgentDelegationResource::canCreate())->toBeFalse();
    expect(AgentDelegationResource::getPages())->not->toHaveKey('create');
    expect(AgentDelegationResource::getPages())->not->toHaveKey('edit');
    expect(AgentExecutionResource::canCreate())->toBeFalse();
    expect(AgentExecutionResource::getPages())->not->toHaveKey('create');
    expect(AgentExecutionResource::getPages())->not->toHaveKey('edit');
    expect(AgentDecisionResource::canCreate())->toBeFalse();
    expect(AgentDecisionResource::getPages())->not->toHaveKey('create');
    expect(AgentDecisionResource::getPages())->not->toHaveKey('edit');
    expect(ApprovalRequestResource::canCreate())->toBeFalse();
    expect(ApprovalRequestResource::getPages())->not->toHaveKey('create');
    expect(ApprovalRequestResource::getPages())->not->toHaveKey('edit');
});

it('keeps runtime descriptor metadata code-authoritative and read-only', function () {
    $schema = AgentDescriptorResource::form(new \Filament\Schemas\Schema);
    $runtimeClass = $schema->getComponents()[1];

    expect($runtimeClass->isDisabled())->toBeTrue();
    expect($runtimeClass->isDehydrated())->toBeFalse();
});

it('authorizes delegation through its server-side policy', function () {
    $local = AgentDelegation::factory()->succeeded()->create();
    $foreign = AgentDelegation::factory()->succeeded()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $local->organization_id,
    ]);

    expect(Gate::forUser($user)->allows('view', $local))->toBeTrue();
    expect(Gate::forUser($user)->allows('view', $foreign))->toBeFalse();
    expect(Gate::forUser($user)->allows('update', $local))->toBeFalse();
    expect(Gate::forUser($user)->allows('delete', $local))->toBeFalse();
});

it('exposes the collaboration report only for authorized enterprises', function () {
    $delegation = AgentDelegation::factory()->succeeded()->create();
    $enterprise = Enterprise::query()->findOrFail($delegation->enterprise_id);
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $enterprise->organization_id,
    ]);

    $this->actingAs($user);

    expect(AgentCollaborationReport::canAccess())->toBeTrue();

    $page = new AgentCollaborationReport;
    $page->mount();

    expect($page->reports)->toHaveCount(1);
    expect($page->reports[0]['enterprise']['id'])->toBe($enterprise->getKey());
});
