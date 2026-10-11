<?php

use App\Capabilities\CapabilityRegistry;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\UpdateEnterpriseContextTool;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Operations\EnterpriseContextUpdate;

it('registers update enterprise context as one explicit capability and operation', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->id, 'organization_id' => $organization->id]);

    Cr8orServer::actingAs($actor, 'api')->tools()->assertRegistered([UpdateEnterpriseContextTool::class]);

    $definition = app(CapabilityRegistry::class)->forTool(UpdateEnterpriseContextTool::class);
    expect($definition->key)->toBe('enterprise.context.update')
        ->and($definition->operation)->toBe(EnterpriseContextUpdate::class);
});

it('updates existing enterprise context fields without changing enterprise ownership', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    Membership::factory()->admin()->create(['user_id' => $actor->id, 'organization_id' => $organization->id]);
    $context = EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise->id,
        'description' => 'Old description',
        'industry' => 'Old industry',
        'business_model' => 'Existing model',
        'additional_context' => ['old' => true],
    ]);

    Cr8orServer::actingAs($actor, 'api')->tool(UpdateEnterpriseContextTool::class, [
        'enterprise_context_id' => $context->id,
        'description' => 'Updated enterprise description',
        'industry' => 'Education technology',
        'additional_context' => ['positioning' => 'practical learning'],
    ])->assertOk()->assertSee(['Updated enterprise description', 'Education technology']);

    expect($context->refresh()->enterprise_id)->toBe($enterprise->id)
        ->and($context->description)->toBe('Updated enterprise description')
        ->and($context->industry)->toBe('Education technology')
        ->and($context->business_model)->toBe('Existing model')
        ->and($context->additional_context)->toBe(['positioning' => 'practical learning']);
});

it('denies updates to enterprise context belonging to another organization', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->id, 'organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization]);
    $context = EnterpriseContext::factory()->create(['enterprise_id' => $foreignEnterprise->id, 'industry' => 'Original']);

    Cr8orServer::actingAs($actor, 'api')->tool(UpdateEnterpriseContextTool::class, [
        'enterprise_context_id' => $context->id,
        'industry' => 'Unauthorized change',
    ])->assertHasErrors();

    expect($context->refresh()->industry)->toBe('Original');
});

it('rejects an update request with no mutable context fields', function () {
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    Membership::factory()->owner()->create(['user_id' => $actor->id, 'organization_id' => $organization->id]);
    $context = EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->id, 'industry' => 'Original']);

    Cr8orServer::actingAs($actor, 'api')->tool(UpdateEnterpriseContextTool::class, [
        'enterprise_context_id' => $context->id,
    ])->assertHasErrors();

    expect($context->refresh()->industry)->toBe('Original');
});
