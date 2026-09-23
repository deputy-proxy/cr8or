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
