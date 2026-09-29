<?php

use App\AI\Contracts\ExecutionErrorType;
use App\AI\Contracts\FailureCode;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\Exceptions\CanvaClientException;
use App\Exceptions\IntegrationProviderException;
use App\Exceptions\MediaStorageException;
use App\Exceptions\PublishingProviderException;
use App\Services\DiagnosticSanitizer;
use App\Services\FailureTranslator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

it('translates expected exception families into canonical failures', function () {
    $cases = [
        'authentication' => [new AuthenticationException, ExecutionErrorType::Authentication, FailureCode::AUTHENTICATION_REQUIRED, false],
        'authorization' => [new AuthorizationException, ExecutionErrorType::Authorization, FailureCode::AUTHORIZATION_DENIED, false],
        'validation' => [ValidationException::withMessages(['name' => 'required']), ExecutionErrorType::Validation, FailureCode::VALIDATION_FAILED, false],
        'not found' => [new ModelNotFoundException, ExecutionErrorType::Resource, FailureCode::RESOURCE_NOT_FOUND, false],
        'database conflict' => [new QueryException('sqlite', 'insert', [], new PDOException('constraint', 23000)), ExecutionErrorType::Conflict, FailureCode::CONFLICT_DETECTED, false],
        'database deadlock' => [new PDOException('deadlock', 40001), ExecutionErrorType::Persistence, FailureCode::PERSISTENCE_FAILED, true],
        'queue exhausted' => [new MaxAttemptsExceededException('exhausted'), ExecutionErrorType::Queue, FailureCode::QUEUE_FAILED, false],
        'queue timeout' => [new TimeoutExceededException('timed out'), ExecutionErrorType::Queue, FailureCode::QUEUE_TIMEOUT, true],
        'external connection' => [new ConnectionException('connection failed'), ExecutionErrorType::External, FailureCode::EXTERNAL_UNAVAILABLE, true],
        'external authentication' => [new RequestException(new \Illuminate\Http\Client\Response(new PsrResponse(401))), ExecutionErrorType::External, FailureCode::EXTERNAL_AUTHENTICATION, false],
        'external rate limit' => [new RequestException(new \Illuminate\Http\Client\Response(new PsrResponse(429))), ExecutionErrorType::External, FailureCode::EXTERNAL_RATE_LIMITED, true],
        'external unavailable' => [new RequestException(new \Illuminate\Http\Client\Response(new PsrResponse(503))), ExecutionErrorType::External, FailureCode::EXTERNAL_UNAVAILABLE, true],
        'provider timeout' => [new ModelProviderException(ModelProviderFailureType::Timeout, 'fake', 'provider timeout'), ExecutionErrorType::Provider, FailureCode::PROVIDER_TIMEOUT, true],
        'provider configuration' => [new ModelProviderException(ModelProviderFailureType::Configuration, 'fake', 'provider configuration'), ExecutionErrorType::Provider, FailureCode::PROVIDER_CONFIGURATION, false],
        'canva timeout' => [new CanvaClientException('timeout', 'timeout', true), ExecutionErrorType::External, FailureCode::EXTERNAL_TIMEOUT, true],
        'publishing rejection' => [new PublishingProviderException('rejected', 'provider_rejected', false), ExecutionErrorType::External, FailureCode::EXTERNAL_REJECTED, false],
        'serialization' => [new JsonException('secret json context'), ExecutionErrorType::Serialization, FailureCode::SERIALIZATION_FAILED, false],
        'invalid argument' => [new InvalidArgumentException('private implementation detail'), ExecutionErrorType::Validation, FailureCode::VALIDATION_FAILED, false],
        'business rule' => [new LogicException('private business rule'), ExecutionErrorType::BusinessRule, FailureCode::BUSINESS_RULE_REJECTED, false],
        'unexpected' => [new RuntimeException('secret internal failure'), ExecutionErrorType::Internal, FailureCode::INTERNAL_UNEXPECTED, false],
    ];

    foreach ($cases as $case) {
        [$exception, $type, $code, $retryable] = $case;
        $failure = app(FailureTranslator::class)->translate($exception, correlationId: 'corr-280');

        expect($failure->type)->toBe($type)
            ->and($failure->code)->toBe($code)
            ->and($failure->retryable)->toBe($retryable)
            ->and($failure->correlationId)->toBe('corr-280')
            ->and($failure->diagnosticId)->toBeString()->not->toBeEmpty();
    }
});

it('records the original exception diagnostics once and keeps them out of the client failure', function () {
    Log::spy();

    $exception = new RuntimeException('secret internal failure');
    $translator = app(FailureTranslator::class);

    $first = $translator->translate($exception, correlationId: 'corr-280');
    $second = $translator->translate($exception, correlationId: 'corr-280');

    expect($second->diagnosticId)->toBe($first->diagnosticId)
        ->and(json_encode($first->toArray()))->not->toContain('secret internal failure');

    Log::shouldHaveReceived('error')
        ->once()
        ->with('CR8OR failure diagnostic.', Mockery::on(
            fn (array $context): bool => $context['diagnostic_id'] === $first->diagnosticId
                && $context['correlation_id'] === 'corr-280'
                && $context['exception_class'] === RuntimeException::class
                && $context['exception_message'] === 'secret internal failure'
                && is_string($context['exception_trace']),
        ));
});

it('keeps validation details while using a safe external message', function () {
    $failure = app(FailureTranslator::class)->translate(
        ValidationException::withMessages([
            'name' => ['The name field is required.'],
        ]),
        correlationId: 'corr-validation',
    );

    expect($failure->message)->toBe('The request failed validation.')
        ->and($failure->details)->toBe([
            'fields' => [
                'name' => ['The name field is required.'],
            ],
        ]);
});

it('renders unexpected JSON API exceptions through the canonical failure contract', function () {
    $response = $this->getJson('/api/nonexistent-route');

    $response->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', FailureCode::RESOURCE_NOT_FOUND)
        ->assertJsonPath('error.retryable', false)
        ->assertJsonStructure([
            'success',
            'error' => [
                'type',
                'code',
                'message',
                'retryable',
                'correlation_id',
                'diagnostic_id',
            ],
        ]);
});

it('maps external integration and storage failures to canonical retry-aware codes', function () {
    $translator = app(FailureTranslator::class);

    $rate = $translator->translate(new IntegrationProviderException(
        'Provider rate limit.',
        'rate_limited',
        true,
    ));
    $storage = $translator->translate(new MediaStorageException(
        'Storage unavailable.',
        'storage.unavailable',
        true,
    ));

    expect($rate->code)->toBe(FailureCode::EXTERNAL_RATE_LIMITED)
        ->and($rate->retryable)->toBeTrue()
        ->and($storage->code)->toBe(FailureCode::EXTERNAL_UNAVAILABLE)
        ->and($storage->retryable)->toBeTrue();
});

it('redacts credentials and model context from diagnostic values', function () {
    $sanitizer = app(DiagnosticSanitizer::class);

    $message = $sanitizer->message(
        'Authorization: Bearer super-secret-token prompt=private-model-context',
    );

    expect($message)->not->toContain('super-secret-token')
        ->and($message)->not->toContain('private-model-context')
        ->and($message)->toContain('[REDACTED]');
});