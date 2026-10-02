<?php

use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\Services\CanonicalWorkflowProvisioner;
use App\Services\MarketingStrategyWorkflowDefinition;

it('provisions the canonical marketing Workflow once and publishes it', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $provisioner = app(CanonicalWorkflowProvisioner::class);

    $first = $provisioner->provisionMarketingStrategy($enterprise, $actor);
    $second = $provisioner->provisionMarketingStrategy($enterprise, $actor);

    expect($first->getKey())->toBe($second->getKey())
        ->and($first->canonical_key)->toBe(MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE)
        ->and($first->stages)->toHaveCount(18)
        ->and($first->publishedVersion?->status)->toBe(WorkflowVersion::STATUS_PUBLISHED)
        ->and($first->versions()->count())->toBe(1);
});

it('does not replace an existing published canonical version', function (): void {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $provisioner = app(CanonicalWorkflowProvisioner::class);
    $workflow = $provisioner->provisionMarketingStrategy($enterprise, $actor);
    $versionId = $workflow->published_version_id;
    $stageSnapshot = $workflow->publishedVersion->stage_definitions;

    $provisioned = $provisioner->provisionMarketingStrategy($enterprise, $actor);

    expect($provisioned->published_version_id)->toBe($versionId)
        ->and($provisioned->publishedVersion->stage_definitions)->toBe($stageSnapshot)
        ->and($provisioned->versions()->count())->toBe(1);
});
