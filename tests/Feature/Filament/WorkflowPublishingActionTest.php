<?php

use App\Filament\Resources\Workflows\Pages\ListWorkflows;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\User;
use App\Services\WorkflowEntryPointService;
use App\Services\WorkflowVersionService;
use Livewire\Livewire;

it('publishes a valid workflow from the Filament list and creates one immutable version', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);
    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'business-analysis'],
        ['runtime_class' => App\Experts\BusinessAnalysisExpert::class, 'enabled' => true],
    );

    $workflow = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Publish Action Test',
        'canonical_key' => 'workflow.publish-action.test',
        'stages' => [[
            'key' => 'analysis',
            'name' => 'Analyze request',
            'sequence' => 1,
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'input_contract' => ['required' => ['request']],
            'output_contract' => ['required' => ['analysis']],
        ]],
    ]);

    $this->actingAs($actor);

    Livewire::test(ListWorkflows::class)
        ->assertStatus(200)
        ->assertTableActionExists('publish')
        ->callTableAction('publish', $workflow);

    $workflow->refresh();
    expect($workflow->published_version_id)->not->toBeNull()
        ->and($workflow->publishedVersion->status)->toBe('published')
        ->and($workflow->versions()->count())->toBe(1);
});

it('does not publish an invalid workflow and exposes actionable validation errors', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);
    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'business-analysis'],
        ['runtime_class' => App\Experts\BusinessAnalysisExpert::class, 'enabled' => true],
    );

    $workflow = app(WorkflowEntryPointService::class)->create($actor, $enterprise, [
        'name' => 'Invalid Publish Action Test',
        'stages' => [[
            'key' => 'analysis',
            'name' => 'Analyze request',
            'sequence' => 1,
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
            'input_contract' => ['required' => ['request']],
            'output_contract' => ['required' => ['analysis']],
        ]],
    ]);
    $workflow->stages()->firstOrFail()->update([
        'expert_slugs' => ['marketing'],
        'capability_slugs' => ['marketing.channel.create'],
    ]);

    $this->actingAs($actor);

    Livewire::test(ListWorkflows::class)
        ->callTableAction('publish', $workflow)
        ->assertNotified('Workflow validation failed');

    expect($workflow->refresh()->published_version_id)->toBeNull()
        ->and($workflow->versions()->count())->toBe(0);
});

it('requires workflow update authorization for publication at the domain boundary', function (): void {
    $enterprise = Enterprise::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $owner->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);
    $viewer = User::factory()->create();
    Membership::factory()->create([
        'role' => App\Enums\MembershipRole::Member,
        'user_id' => $viewer->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);
    ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'business-analysis'],
        ['runtime_class' => App\Experts\BusinessAnalysisExpert::class, 'enabled' => true],
    );

    $workflow = app(WorkflowEntryPointService::class)->create($owner, $enterprise, [
        'name' => 'Authorization Publish Test',
        'stages' => [[
            'key' => 'analysis',
            'name' => 'Analyze request',
            'sequence' => 1,
            'expert_slugs' => ['business-analysis'],
            'capability_slugs' => ['business.analysis'],
        ]],
    ]);

    expect(fn () => app(WorkflowVersionService::class)->publish($workflow, $viewer, 'viewer-cannot-publish'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($workflow->refresh()->published_version_id)->toBeNull()
        ->and($workflow->versions()->count())->toBe(0);
});