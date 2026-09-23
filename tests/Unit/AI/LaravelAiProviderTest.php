<?php

use App\AI\Data\ModelRequest;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\LaravelAiProvider;
use Closure;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\StructuredAnonymousAgent;

uses(Tests\TestCase::class);

it('normalizes a text response without calling a real provider', function () {
    AnonymousAgent::fake(['Provider response.']);

    $provider = new LaravelAiProvider;

    $result = $provider->generate(new ModelRequest(
        prompt: 'Summarize this.',
        instructions: 'Be concise.',
        provider: 'openai',
        model: 'gpt-test',
        correlationId: 'corr-text',
    ));

    expect($result->text)->toBe('Provider response.')
        ->and($result->provider)->toBe('openai')
        ->and($result->model)->toBe('gpt-test')
        ->and($result->correlationId)->toBe('corr-text')
        ->and($result->invocationId)->not->toBeEmpty();

    AnonymousAgent::assertPrompted(function ($prompt): bool {
        return $prompt->contains('Summarize this.');
    });
});

it('normalizes a structured response without calling a real provider', function () {
    StructuredAnonymousAgent::fake([
        ['decision' => 'approve'],
    ]);

    $provider = new LaravelAiProvider;

    $result = $provider->generate(new ModelRequest(
        prompt: 'Decide.',
        instructions: 'Return only the decision.',
        provider: 'openai',
        model: 'gpt-test',
        structuredOutputSchema: [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'required' => true],
            ],
        ],
    ));

    expect($result->structured)->toBe(['decision' => 'approve']);
});

it('maps provider rate limits to the internal failure contract', function () {
    $provider = new LaravelAiProvider(
        static fn (string $instructions, iterable $messages = [], iterable $tools = [], ?Closure $schema = null) => throw new \Laravel\Ai\Exceptions\RateLimitedException('rate limited'),
    );

    $exception = null;

    try {
        $provider->generate(new ModelRequest(
            prompt: 'Test.',
            provider: 'openai',
        ));
    } catch (ModelProviderException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ModelProviderException::class)
        ->and($exception->type)->toBe(ModelProviderFailureType::RateLimited)
        ->and($exception->provider)->toBe('openai')
        ->and($exception->getMessage())->not->toContain('rate limited');
});
