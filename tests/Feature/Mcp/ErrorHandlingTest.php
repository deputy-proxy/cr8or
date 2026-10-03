<?php

use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ExecutionErrorType;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

it('classifies authentication, authorization, validation and business failures', function () {
    expect(ExecutionError::from(new AuthenticationException)->type)->toBe(ExecutionErrorType::Authentication)
        ->and(ExecutionError::from(new AuthorizationException)->type)->toBe(ExecutionErrorType::Authorization)
        ->and(ExecutionError::from(ValidationException::withMessages(['name' => 'required']))->type)->toBe(ExecutionErrorType::Validation)
        ->and(ExecutionError::from(new LogicException('business rule'))->type)->toBe(ExecutionErrorType::BusinessRule);
});

it('classifies provider failures and marks retryable provider classes', function () {
    $unavailable = ExecutionError::from(new ModelProviderException(ModelProviderFailureType::Unavailable, 'fake', 'provider unavailable'));
    $timeout = ExecutionError::from(new ModelProviderException(ModelProviderFailureType::Timeout, 'fake', 'provider timeout'));
    $rateLimited = ExecutionError::from(new ModelProviderException(ModelProviderFailureType::RateLimited, 'fake', 'provider rate limited'));

    expect($unavailable->type)->toBe(ExecutionErrorType::Provider)
        ->and($unavailable->code)->toBe('provider.unavailable')
        ->and($unavailable->retryable)->toBeTrue()
        ->and($timeout->retryable)->toBeTrue()
        ->and($rateLimited->retryable)->toBeTrue();
});

it('does not expose provider exception details in the normalized public error', function () {
    $error = ExecutionError::from(new ModelProviderException(ModelProviderFailureType::Unavailable, 'fake', 'secret-provider-detail'));
    $payload = $error->toArray('correlation-123');

    expect(json_encode($payload))->not->toContain('secret-provider-detail')
        ->and($payload['error']['correlation_id'])->toBe('correlation-123');
});

it('returns a normalized MCP error for a business-rule failure', function () {
    $actor = \App\Models\User::factory()->create();
    $organization = \App\Models\Organization::factory()->create();
    \App\Models\Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = \App\Models\Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $workItem = \App\Models\WorkItem::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    \App\Mcp\Servers\Cr8orServer::actingAs($actor, 'api')
        ->tool(\App\Mcp\Tools\UpdateWorkItemTool::class, ['work_item_id' => $workItem->getKey()])
        ->assertHasErrors()
        ->assertSee('business_rule.rejected')
        ->assertSee('correlation_id');
});
it('exposes the canonical failure contract with provenance and diagnostic identity', function () {
    $error = ExecutionError::from(
        new LogicException('private implementation detail'),
        correlationId: 'corr-279',
        provenance: new \App\AI\Contracts\FailureProvenance(
            operation: 'CreateAgentAssignment',
            capability: 'agent.assignment.create',
            tool: 'mcp_agent_assignment_create',
        ),
    );

    $payload = $error->toArray();

    expect($payload['error'])->toMatchArray([
        'type' => 'business_rule',
        'code' => 'business_rule.rejected',
        'message' => 'The operation was rejected by a business rule.',
        'retryable' => false,
        'correlation_id' => 'corr-279',
        'operation' => 'CreateAgentAssignment',
        'capability' => 'agent.assignment.create',
        'tool' => 'mcp_agent_assignment_create',
        'details' => [],
    ])->and($payload['error']['diagnostic_id'])->toBeString()->not->toBeEmpty()
        ->and(json_encode($payload))->not->toContain('private implementation detail');
});

it('uses stable taxonomy categories for the canonical contract', function () {
    expect(array_map(
        static fn (ExecutionErrorType $type): string => $type->value,
        ExecutionErrorType::cases(),
    ))->toEqualCanonicalizing([
        'authentication',
        'authorization',
        'validation',
        'resource',
        'conflict',
        'lifecycle',
        'business_rule',
        'capability',
        'approval',
        'provider',
        'external',
        'persistence',
        'queue',
        'configuration',
        'serialization',
        'internal',
    ]);
});

it('defines stable machine-readable failure codes centrally', function () {
    expect(\App\AI\Contracts\FailureCode::INTERNAL_UNEXPECTED)->toBe('internal.unexpected')
        ->and(\App\AI\Contracts\FailureCode::VALIDATION_FAILED)->toBe('validation.failed')
        ->and(\App\AI\Contracts\FailureCode::CAPABILITY_DENIED)->toBe('capability.denied')
        ->and(\App\AI\Contracts\FailureCode::PERSISTENCE_FAILED)->toBe('persistence.failed')
        ->and(\App\AI\Contracts\FailureCode::SERIALIZATION_FAILED)->toBe('serialization.failed');
});

it('preserves field-level validation details without exposing exception data', function () {
    $exception = \Illuminate\Validation\ValidationException::withMessages([
        'name' => ['The name field is required.'],
        'email' => ['The email field is invalid.'],
    ]);

    $error = ExecutionError::from($exception, correlationId: 'corr-validation');

    expect($error->type)->toBe(ExecutionErrorType::Validation)
        ->and($error->code)->toBe(\App\AI\Contracts\FailureCode::VALIDATION_FAILED)
        ->and($error->details)->toBe([
            'fields' => [
                'name' => ['The name field is required.'],
                'email' => ['The email field is invalid.'],
            ],
        ])
        ->and($error->toArray()['error']['correlation_id'])->toBe('corr-validation');
});