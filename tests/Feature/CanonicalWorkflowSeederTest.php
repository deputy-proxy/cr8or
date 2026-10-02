<?php

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Services\CanonicalWorkflowProvisioner;
use Database\Seeders\AgentDescriptorSeeder;
use Database\Seeders\CanonicalWorkflowSeeder;
use Database\Seeders\ExpertDescriptorSeeder;

it('seeds valid.guide with the canonical strategy Workflows on a clean database', function (): void {
    $this->seed([
        AgentDescriptorSeeder::class,
        ExpertDescriptorSeeder::class,
    ]);
    User::factory()->create(['email' => 'test@example.com']);

    $this->seed(CanonicalWorkflowSeeder::class);

    $enterprise = Enterprise::query()->where('slug', 'valid.guide')->first();

    expect($enterprise)->not->toBeNull()
        ->and(Workflow::query()->forCanonicalKey($enterprise, 'strategy.create')->count())->toBe(1)
        ->and(Workflow::query()->forCanonicalKey($enterprise, 'marketing.strategy.create')->count())->toBe(1)
        ->and(app(CanonicalWorkflowProvisioner::class)->provisionStrategyCreation($enterprise, User::query()->where('email', 'test@example.com')->first())->versions()->count())->toBe(1)
        ->and(app(CanonicalWorkflowProvisioner::class)->provisionMarketingStrategy($enterprise, User::query()->where('email', 'test@example.com')->first())->versions()->count())->toBe(1);
});

it('keeps DatabaseSeeder deterministic when run repeatedly', function (): void {
    $this->seed();
    $this->seed();

    $enterprise = Enterprise::query()->where('slug', 'valid.guide')->first();

    expect(Workflow::query()->forCanonicalKey($enterprise, 'strategy.create')->count())->toBe(1)
        ->and(Workflow::query()->forCanonicalKey($enterprise, 'marketing.strategy.create')->count())->toBe(1);
});