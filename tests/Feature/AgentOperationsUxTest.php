<?php

use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AgentExecutionOperationsService;
use Illuminate\Auth\Access\AuthorizationException;

it('exposes a governed operational read model without model reasoning', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();
    Membership::factory()->admin()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create([
        'prompt' => 'private reasoning input',
        'model_options' => ['secret_runtime_detail' => 'should not appear'],
        'status' => AgentExecution::STATUS_COMPLETED,
    ]);

    $inspection = app(AgentExecutionOperationsService::class)->inspect($user, $execution);

    expect($inspection)->toHaveKeys(['execution', 'steps', 'timeline', 'approvals', 'delegations', 'integration_results'])
        ->and($inspection['execution'])->not->toHaveKey('prompt')
        ->and($inspection['execution'])->not->toHaveKey('model_options');
});

it('denies operational inspection across organizations', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $execution = AgentExecution::factory()->forEnterprise($enterprise)->create();
    $foreignUser = User::factory()->create();

    expect(fn () => app(AgentExecutionOperationsService::class)->inspect($foreignUser, $execution))
        ->toThrow(AuthorizationException::class);
});