<?php

use App\Filament\Resources\Workflows\Pages\ListWorkflows;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Services\WorkflowEntryPointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes duplicate as a workflow list record action', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Workflow List Action Test',
        'canonical_key' => 'workflow.list-action.test',
        'stages' => [[
            'key' => 'strategy',
            'sequence' => 1,
            'expert_slugs' => ['marketing'],
            'capability_slugs' => ['marketing.strategy.create'],
        ]],
    ]);

    $this->actingAs($actor);

    Livewire::test(ListWorkflows::class)
        ->assertStatus(200)
        ->assertTableActionExists('duplicate');
});
