<?php

use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('creates a context belonging to an enterprise', function () {
    $enterprise = Enterprise::factory()->create();

    $context = EnterpriseContext::factory()->create([
        'enterprise_id' => $enterprise,
        'description' => 'A software company serving small businesses.',
        'industry' => 'Software',
        'business_model' => 'subscription',
        'target_market' => 'Small and medium businesses',
        'geography' => 'Romania',
        'additional_context' => ['values' => ['simplicity', 'trust']],
    ]);

    $context->refresh();

    expect($context->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->context->is($context))->toBeTrue()
        ->and($context->additional_context)->toBe(['values' => ['simplicity', 'trust']]);
});

it('enforces a one-to-one enterprise context relationship', function () {
    $enterprise = Enterprise::factory()->create();
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise]);

    expect(fn () => EnterpriseContext::factory()->create(['enterprise_id' => $enterprise]))
        ->toThrow(QueryException::class);
});

it('cannot persist an enterprise context without an enterprise', function () {
    expect(fn () => EnterpriseContext::factory()->create(['enterprise_id' => 999999]))
        ->toThrow(QueryException::class);
});

it('cascades enterprise context deletion with its enterprise', function () {
    $enterprise = Enterprise::factory()->create();
    $context = EnterpriseContext::factory()->create(['enterprise_id' => $enterprise]);

    $enterprise->delete();

    expect(EnterpriseContext::query()->whereKey($context->getKey())->exists())->toBeFalse();
});

it('keeps enterprise context structured and separate from enterprise identity', function () {
    expect(Schema::getColumnListing('enterprise_contexts'))->toBe([
        'id',
        'enterprise_id',
        'description',
        'industry',
        'business_model',
        'target_market',
        'geography',
        'additional_context',
        'created_at',
        'updated_at',
    ]);
});

it('enforces context authorization through the enterprise organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);
    $context = EnterpriseContext::factory()->create(['enterprise_id' => $enterprise]);
    $foreignContext = EnterpriseContext::factory()->create(['enterprise_id' => $foreignEnterprise]);

    expect(Gate::forUser($owner)->allows('view', $context))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $context))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $context))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $foreignContext))->toBeFalse();
});