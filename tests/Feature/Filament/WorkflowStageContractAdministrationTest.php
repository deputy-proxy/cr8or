<?php

use App\Filament\Resources\Workflows\WorkflowResource;

it('formats capability contracts as exact pretty JSON', function (): void {
    expect(WorkflowResource::formatJsonContract([
        'enterprise_id' => 'integer|required',
        'name' => 'string|required',
    ]))->toBe(<<<'JSON'
{
    "enterprise_id": "integer|required",
    "name": "string|required"
}
JSON
    );
});

it('derives workflow input requirements while preserving defaults and mappings', function (): void {
    $contract = WorkflowResource::workflowInputContract([
        'enterprise_id' => 'integer|required',
        'name' => 'string|required',
        'description' => 'string|nullable',
    ], [
        'required' => ['obsolete_field'],
        'defaults' => ['description' => 'Default description'],
        'mappings' => ['enterprise_id' => 'enterprise.id'],
    ]);

    expect($contract)->toBe([
        'required' => ['enterprise_id', 'name'],
        'defaults' => ['description' => 'Default description'],
        'mappings' => ['enterprise_id' => 'enterprise.id'],
    ]);
});

it('initializes workflow input orchestration state from a capability contract', function (): void {
    expect(WorkflowResource::workflowInputContract([
        'enterprise_id' => 'integer|required',
        'name' => 'string|required',
        'description' => 'string|nullable',
    ]))->toBe([
        'required' => ['enterprise_id', 'name'],
        'defaults' => [],
        'mappings' => [],
    ]);
});

it('accepts serialized workflow input orchestration state', function (): void {
    $contract = WorkflowResource::workflowInputContract(
        ['name' => 'string|required'],
        json_encode([
            'defaults' => ['name' => 'Existing'],
            'mappings' => ['name' => 'context.name'],
        ], JSON_THROW_ON_ERROR),
    );

    expect($contract)->toBe([
        'defaults' => ['name' => 'Existing'],
        'mappings' => ['name' => 'context.name'],
        'required' => ['name'],
    ]);
});
it('uses the canonical capability output contract for workflow output configuration', function (): void {
    $definition = app(\App\Services\ExpertCapabilityResolver::class)->resolve(
        'marketing',
        'marketing.strategy.create',
    );

    expect(\App\Filament\Resources\Workflows\WorkflowResource::formatJsonContract($definition->outputContract))
        ->toBe(json_encode($definition->outputContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});
it('hydrates persisted Workflow stage Expert and Capability selections as scalar Select state', function (): void {
    $organization = \App\Models\Organization::factory()->create();
    $owner = \App\Models\User::factory()->create();
    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $owner->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = \App\Models\Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    \App\Models\ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'marketing'],
        ['runtime_class' => \App\Experts\MarketingExpert::class, 'enabled' => true],
    );

    $workflow = app(\App\Services\WorkflowEntryPointService::class)->create($owner, $enterprise, [
        'name' => 'Workflow hydration test',
        'stages' => [[
            'key' => 'strategy',
            'name' => 'Create strategy',
            'sequence' => 1,
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.strategy.create'],
        ]],
    ]);

    expect($workflow->stages->first()->expert_slugs)->toBe(['marketing'])
        ->and($workflow->stages->first()->capability_slugs)->toBe(['marketing.strategy.create']);

    $this->actingAs($owner);

    \Livewire\Livewire::test(\App\Filament\Resources\Workflows\Pages\EditWorkflow::class, [
        'record' => $workflow->getKey(),
    ])
        ->assertStatus(200)
        ->assertSet('data.stages.record-1.expert_slugs', 'marketing')
        ->assertSet('data.stages.record-1.capability_slugs', 'marketing.strategy.create');
});

it('preserves caller-owned workflow required inputs when editing a persisted stage', function (): void {
    $organization = \App\Models\Organization::factory()->create();
    $owner = \App\Models\User::factory()->create();
    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $owner->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = \App\Models\Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    \App\Models\ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'marketing'],
        ['runtime_class' => \App\Experts\MarketingExpert::class, 'enabled' => true],
    );

    $workflow = app(\App\Services\WorkflowEntryPointService::class)->create($owner, $enterprise, [
        'name' => 'Workflow contract preservation test',
        'stages' => [[
            'key' => 'audience',
            'name' => 'Create audience',
            'sequence' => 1,
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.audience.create'],
            'input_contract' => [
                'required' => ['name', 'description'],
                'defaults' => [],
                'mappings' => [],
            ],
        ]],
    ]);

    $this->actingAs($owner);

    \Livewire\Livewire::test(\App\Filament\Resources\Workflows\Pages\EditWorkflow::class, [
        'record' => $workflow->getKey(),
    ])
        ->assertSet('data.stages.record-1.input_contract', json_encode([
            'required' => ['name', 'description'],
            'defaults' => [],
            'mappings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});
