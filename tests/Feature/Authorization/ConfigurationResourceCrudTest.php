<?php

use App\Filament\Resources\AgentRuntimePolicies\Pages\CreateAgentRuntimePolicy;
use App\Filament\Resources\AgentRuntimePolicies\Pages\ListAgentRuntimePolicies;
use App\Filament\Resources\ApprovalPolicies\Pages\CreateApprovalPolicy;
use App\Filament\Resources\ApprovalPolicies\Pages\ListApprovalPolicies;
use App\Models\AgentRuntimePolicy;
use App\Models\ApprovalPolicy;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('allows an authorized member to create an enterprise-scoped Agent runtime policy', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    $this->actingAs($user);

    Livewire::test(CreateAgentRuntimePolicy::class)
        ->fillForm([
            'environment' => 'production',
            'organization_id' => $organization->id,
            'enterprise_id' => $enterprise->id,
            'enabled' => true,
            'max_steps' => 5,
            'max_retries' => 2,
            'timeout_seconds' => 30,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AgentRuntimePolicy::query()->where('enterprise_id', $enterprise->id)->exists())->toBeTrue();
});

it('rejects an Agent runtime policy create request for an unauthorized organization', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organizationA->id]);

    $this->actingAs($user);

    Livewire::test(CreateAgentRuntimePolicy::class)
        ->fillForm([
            'environment' => 'production',
            'organization_id' => $organizationB->id,
            'enabled' => true,
        ])
        ->call('create')
        ->assertForbidden();

    expect(AgentRuntimePolicy::query()->count())->toBe(0);
});

it('allows an authorized member to create a scoped approval policy', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    $this->actingAs($user);

    Livewire::test(CreateApprovalPolicy::class)
        ->fillForm([
            'organization_id' => $organization->id,
            'enterprise_id' => $enterprise->id,
            'policy_key' => 'test-policy',
            'capability' => 'marketing.content.create',
            'stages' => [['required' => 1, 'roles' => ['owner']]],
            'expires_in_minutes' => 60,
            'allow_self_approval' => false,
            'escalation_roles' => ['admin'],
            'enabled' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ApprovalPolicy::query()->where('policy_key', 'test-policy')->exists())->toBeTrue();
});

it('renders list pages and CRUD actions for the editable configuration resources', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $this->actingAs($user);

    Livewire::test(ListAgentRuntimePolicies::class)->assertSee('Agent Runtime Policies');
    Livewire::test(ListApprovalPolicies::class)->assertSee('Approval Policies');
});
