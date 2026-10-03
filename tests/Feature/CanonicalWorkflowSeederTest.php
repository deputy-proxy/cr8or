<?php

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\CanonicalWorkflowSeeder;

it('seeds valid.guide with persisted canonical workflows on a clean database', function (): void {
    User::factory()->create(['email' => 'test@example.com']);

    $this->seed(CanonicalWorkflowSeeder::class);

    $enterprise = Enterprise::query()->where('slug', 'valid.guide')->firstOrFail();

    $strategy = Workflow::query()->forCanonicalKey($enterprise, 'strategy.create')->firstOrFail();
    $marketing = Workflow::query()->forCanonicalKey($enterprise, 'marketing.strategy.create')->firstOrFail();

    expect($strategy->publishedVersion?->status)->toBe('published')
        ->and($strategy->stages)->toHaveCount(1)
        ->and($marketing->publishedVersion?->status)->toBe('published')
        ->and($marketing->stages)->toHaveCount(18)
        ->and($marketing->publishedVersion?->stage_definitions)->toHaveCount(18);
});

it('keeps canonical workflow provisioning deterministic when the seeder runs repeatedly', function (): void {
    $this->seed();

    $this->seed();

    $enterprise = Enterprise::query()->where('slug', 'valid.guide')->firstOrFail();

    expect(Workflow::query()->forCanonicalKey($enterprise, 'strategy.create')->count())->toBe(1)
        ->and(Workflow::query()->forCanonicalKey($enterprise, 'marketing.strategy.create')->count())->toBe(1)
        ->and(Workflow::query()->forCanonicalKey($enterprise, 'strategy.create')->firstOrFail()->versions()->count())->toBe(1)
        ->and(Workflow::query()->forCanonicalKey($enterprise, 'marketing.strategy.create')->firstOrFail()->versions()->count())->toBe(1);
});