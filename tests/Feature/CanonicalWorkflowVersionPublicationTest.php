<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\Services\CanonicalWorkflowProvisioner;

it('keeps the published canonical WorkflowVersion immutable and stable', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $workflow = app(CanonicalWorkflowProvisioner::class)->provisionMarketingStrategy($enterprise, $actor);
    $version = $workflow->publishedVersion;
    $originalStages = $version->stage_definitions;

    expect($version->status)->toBe(WorkflowVersion::STATUS_PUBLISHED)
        ->and(fn () => $version->update(['stage_definitions' => [['key' => 'mutated']]]))
        ->toThrow(LogicException::class);

    expect($version->refresh()->stage_definitions)->toBe($originalStages)
        ->and(app(CanonicalWorkflowProvisioner::class)->provisionMarketingStrategy($enterprise, $actor)->published_version_id)
            ->toBe($version->getKey());
});
