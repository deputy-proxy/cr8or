<?php

use App\Filament\Pages\WorkOverview;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;

it('scopes Work Overview counts to organizations the user can access', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    $visibleEnterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $hiddenEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $visibleProject = Project::factory()->create(['enterprise_id' => $visibleEnterprise]);
    $hiddenProject = Project::factory()->create(['enterprise_id' => $hiddenEnterprise]);

    Task::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    Task::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    WorkItem::factory()->create(['enterprise_id' => $visibleEnterprise, 'project_id' => $visibleProject]);
    WorkItem::factory()->create(['enterprise_id' => $hiddenEnterprise, 'project_id' => $hiddenProject]);

    $this->actingAs($user);

    expect(WorkOverview::canAccess())->toBeTrue();

    $page = app(WorkOverview::class);
    $page->mount();

    $overview = collect($page->overview)->keyBy('label');

    expect($overview['Projects']['count'])->toBe(1)
        ->and($overview['Tasks']['count'])->toBe(1)
        ->and($overview['Work Items']['count'])->toBe(1);

    $nodeIds = collect($page->sankeyData['nodes'])->pluck('name');
    $linkPairs = collect($page->sankeyData['links'])->map(fn (array $link): string => $link['source'].'>'.$link['target']);

    expect($nodeIds)
        ->toContain('enterprise:'.$visibleEnterprise->id)
        ->toContain('project:'.$visibleProject->id)
        ->not->toContain('enterprise:'.$hiddenEnterprise->id)
        ->not->toContain('project:'.$hiddenProject->id)
        ->and($linkPairs)->toContain('enterprise:'.$visibleEnterprise->id.'>project:'.$visibleProject->id);
});

it('denies Work Overview access when the user has no organization membership', function () {
    $this->actingAs(User::factory()->create());

    expect(WorkOverview::canAccess())->toBeFalse();
});