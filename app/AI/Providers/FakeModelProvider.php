<?php

namespace App\AI\Providers;

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use Closure;

final class FakeModelProvider implements ModelProvider
{
    /**
     * @param  Closure(ModelRequest): ModelResult  $handler
     */
    public function __construct(private readonly Closure $handler) {}

    public function generate(ModelRequest $request): ModelResult
    {
        return ($this->handler)($request);
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    public static function returning(
        string $text = 'Fake model response.',
        ?array $structured = null,
    ): self {
        return new self(
            static fn (ModelRequest $request): ModelResult => new ModelResult(
                text: $text,
                structured: $structured,
                provider: $request->provider ?? 'fake',
                model: $request->model ?? 'fake-model',
                invocationId: 'fake-invocation',
                correlationId: $request->correlationId,
            ),
        );
    }
}
