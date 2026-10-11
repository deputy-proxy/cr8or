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
        'mission' => 'Help small businesses operate with clarity.',
        'vision' => 'Make dependable software accessible.',
        'values' => [['name' => 'Trust', 'description' => 'Earn trust through reliable work.']],
        'industry' => 'Software',
        'business_model' => 'subscription',
        'target_market' => 'Small and medium businesses',
        'geography' => 'Romania',
        'additional_context' => ['values' => ['simplicity', 'trust']],
    ]);

    $context->refresh();

    expect($context->enterprise->is($enterprise))->toBeTrue()
        ->and($enterprise->context->is($context))->toBeTrue()
        ->and($context->additional_context)->toBe(['values' => ['simplicity', 'trust']])
        ->and($context->mission)->toBe('Help small businesses operate with clarity.')
        ->and($context->vision)->toBe('Make dependable software accessible.')
        ->and($context->values)->toBe([['name' => 'Trust', 'description' => 'Earn trust through reliable work.']]);
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

it('stores mission, vision, and structured values on the canonical enterprise context', function () {
    expect(Schema::getColumnListing('enterprise_contexts'))
        ->toContain('mission', 'vision', 'values')
        ->and(Schema::hasTable('missions'))->toBeFalse()
        ->and(Schema::hasTable('visions'))->toBeFalse();
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

it('provides mission and vision fields plus a structured values Builder in Filament', function () {
    $schema = \App\Filament\Resources\EnterpriseContexts\EnterpriseContextResource::form(
        \Filament\Schemas\Schema::make()->model(EnterpriseContext::class),
    );
    $components = collect($schema->getComponents())->keyBy(fn ($component) => $component->getName());

    expect($components->get('mission'))->toBeInstanceOf(\Filament\Forms\Components\Textarea::class)
        ->and($components->get('vision'))->toBeInstanceOf(\Filament\Forms\Components\Textarea::class)
        ->and($components->get('values'))->toBeInstanceOf(\Filament\Forms\Components\Builder::class);
});

it('normalizes missing values to an empty list and rejects malformed values at the model boundary', function () {
    $enterprise = Enterprise::factory()->create();
    $context = EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->id, 'values' => null]);

    expect($context->refresh()->values)->toBe([]);

    expect(fn () => EnterpriseContext::factory()->create([
        'enterprise_id' => Enterprise::factory(),
        'values' => [['name' => '   ', 'description' => null]],
    ]))->toThrow(\LogicException::class, 'Each enterprise value');
});

it('persists structured enterprise values through the Filament Builder form', function () {
    $organization = \App\Models\Organization::factory()->create();
    $owner = \App\Models\User::factory()->create();
    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $context = EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->id]);

    $this->actingAs($owner);

    \Livewire\Livewire::test(\App\Filament\Resources\EnterpriseContexts\Pages\EditEnterpriseContext::class, [
        'record' => $context->getKey(),
    ])
        ->set('data.mission', 'Serve customers clearly.')
        ->set('data.vision', 'Build a more useful future.')
        ->set('data.values', [
            'record-1' => ['type' => 'value', 'data' => ['name' => 'Trust', 'description' => 'Keep commitments.']],
            'record-2' => ['type' => 'value', 'data' => ['name' => 'Clarity', 'description' => 'Communicate plainly.']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($context->refresh()->mission)->toBe('Serve customers clearly.')
        ->and($context->vision)->toBe('Build a more useful future.')
        ->and($context->values)->toBe([
            ['name' => 'Trust', 'description' => 'Keep commitments.'],
            ['name' => 'Clarity', 'description' => 'Communicate plainly.'],
        ]);
});
