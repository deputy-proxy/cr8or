<?php

namespace App\AI\Providers;

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

final class LaravelAiProvider implements ModelProvider
{
    /**
     * @param  Closure(string, iterable<mixed>, iterable<mixed>, Closure|null): \Laravel\Ai\Contracts\Agent  $agentFactory
     */
    public function __construct(
        private readonly ?Closure $agentFactory = null,
    ) {}

    public function generate(ModelRequest $request): ModelResult
    {
        $provider = $request->provider ?? (string) config('ai.default', 'openai');
        $model = $request->model;
        $timeout = $request->timeout;

        try {
            $agent = $this->makeAgent($request);

            $response = $agent->prompt(
                $this->buildPrompt($request),
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $structured = $request->requiresStructuredOutput()
                ? $this->normalizeStructuredResponse($response)
                : null;

            return new ModelResult(
                text: $response->text,
                structured: $structured,
                provider: $response->meta->provider ?? $provider,
                model: $response->meta->model ?? $model ?? '',
                invocationId: $response->invocationId,
                usage: $response->usage->toArray(),
                correlationId: $request->correlationId,
            );
        } catch (ModelProviderException $exception) {
            throw $exception;
        } catch (RateLimitedException $exception) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::RateLimited,
                provider: $provider,
                message: 'The model provider rate limit was reached.',
                statusCode: $this->statusCode($exception),
                previous: $exception,
            );
        } catch (ProviderOverloadedException|ProviderConnectionException $exception) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::Unavailable,
                provider: $provider,
                message: 'The model provider is currently unavailable.',
                statusCode: $this->statusCode($exception),
                previous: $exception,
            );
        } catch (InvalidArgumentException $exception) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::Configuration,
                provider: $provider,
                message: 'The model provider configuration or request is invalid.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::Provider,
                provider: $provider,
                message: 'The model provider failed to complete the request.',
                statusCode: $this->statusCode($exception),
                previous: $exception,
            );
        }
    }

    private function makeAgent(ModelRequest $request): \Laravel\Ai\Contracts\Agent
    {
        $factory = $this->agentFactory ?? static fn (
            string $instructions,
            iterable $messages = [],
            iterable $tools = [],
            ?Closure $schema = null,
        ): \Laravel\Ai\Contracts\Agent => \Laravel\Ai\agent($instructions, $messages, $tools, $schema);

        if (! $request->requiresStructuredOutput()) {
            return $factory($request->instructions, [], [], null);
        }

        $schema = $request->structuredOutputSchema;

        return $factory(
            $request->instructions,
            [],
            [],
            static function (JsonSchema $jsonSchema) use ($schema): array {
                return LaravelAiProvider::buildSchema($jsonSchema, $schema ?? []);
            },
        );
    }

    private function buildPrompt(ModelRequest $request): string
    {
        if ($request->context === []) {
            return $request->prompt;
        }

        try {
            $context = json_encode(
                $request->context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (Throwable $exception) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::InvalidResponse,
                provider: $request->provider ?? (string) config('ai.default', 'openai'),
                message: 'The model request context could not be encoded.',
                previous: $exception,
            );
        }

        return $request->prompt."\n\nAuthorized context:\n".$context;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function buildSchema(JsonSchema $schema, array $definition): array
    {
        if (($definition['type'] ?? null) !== 'object') {
            throw new ModelProviderException(
                type: ModelProviderFailureType::InvalidResponse,
                provider: (string) config('ai.default', 'openai'),
                message: 'Structured model output must use an object schema.',
            );
        }

        $properties = [];

        foreach (($definition['properties'] ?? []) as $name => $property) {
            if (! is_string($name) || ! is_array($property)) {
                throw new ModelProviderException(
                    type: ModelProviderFailureType::InvalidResponse,
                    provider: (string) config('ai.default', 'openai'),
                    message: 'Structured model output contains an invalid property definition.',
                );
            }

            $properties[$name] = self::buildPropertySchema($schema, $property);
        }

        return [
            'response' => $schema->object($properties)->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function buildPropertySchema(JsonSchema $schema, array $definition): mixed
    {
        $type = $definition['type'] ?? null;

        $property = match ($type) {
            'string' => $schema->string(),
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            'boolean' => $schema->boolean(),
            'array' => $schema->array()->items(
                isset($definition['items']) && is_array($definition['items'])
                    ? self::buildPropertySchema($schema, $definition['items'])
                    : $schema->string(),
            ),
            default => throw new ModelProviderException(
                type: ModelProviderFailureType::InvalidResponse,
                provider: (string) config('ai.default', 'openai'),
                message: 'Structured model output contains an unsupported property type.',
            ),
        };

        return ($definition['required'] ?? false) ? $property->required() : $property;
    }

    /** @return array<string, mixed> */
    private function normalizeStructuredResponse(object $response): array
    {
        if (! method_exists($response, 'toArray')) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::InvalidResponse,
                provider: (string) config('ai.default', 'openai'),
                message: 'The model provider returned an invalid structured response.',
            );
        }

        $structured = $response->toArray();

        if (! is_array($structured)) {
            throw new ModelProviderException(
                type: ModelProviderFailureType::InvalidResponse,
                provider: (string) config('ai.default', 'openai'),
                message: 'The model provider returned invalid structured data.',
            );
        }

        return $structured;
    }

    private function statusCode(Throwable $exception): ?int
    {
        $response = method_exists($exception, 'response') ? $exception->response() : null;

        return $response?->status();
    }
}
