<?php

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;

it('exposes a deterministic fake provider through the internal contract', function () {
    $provider = FakeModelProvider::returning(
        text: 'Deterministic response.',
        structured: ['decision' => 'approve'],
    );

    $result = $provider->generate(new ModelRequest(
        prompt: 'Decide.',
        provider: 'fake',
        model: 'fake-model',
        correlationId: 'corr-123',
        structuredOutputSchema: [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'required' => true],
            ],
        ],
    ));

    expect($provider)->toBeInstanceOf(ModelProvider::class)
        ->and($result)->toBeInstanceOf(ModelResult::class)
        ->and($result->text)->toBe('Deterministic response.')
        ->and($result->structured)->toBe(['decision' => 'approve'])
        ->and($result->correlationId)->toBe('corr-123');
});
