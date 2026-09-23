<?php

use App\Agents\Agent;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\Providers\FakeModelProvider;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\McpContextAssembler;

function executionFailureAgentClass(): string
{
    return get_class(new class extends Agent
    {
        public function name(): string
        {
            return 'Failure Test Agent';
        }

        public function description(): string
        {
            return 'Exercises failure handling.';
        }

        public function responsibilities(): array
        {
            return ['test'];
        }

        public function capabilities(): array
        {
            return [];
        }

        public function requiredContext(): array
        {
            return ['enterprise', 'knowledge', 'strategy', 'work'];
        }
    });
}

it('records a failed provider execution without persisting a successful decision', function () {
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);
    EnterpriseContext::factory()->create(['enterprise_id' => $enterprise->getKey()]);

    $descriptor = AgentDescriptor::factory()->forRuntimeClass(executionFailureAgentClass())->create();
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create([
        'agent_descriptor_id' => $descriptor->getKey(),
    ]);

    $provider = new FakeModelProvider(fn () => throw new ModelProviderException(
        ModelProviderFailureType::Unavailable,
        'fake',
        'provider unavailable',
    ));

    $service = new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    );

    expect(fn () => $service->execute($actor, $assignment, 'Fail safely.'))
        ->toThrow(ModelProviderException::class);

    $execution = AgentExecution::query()->latest('id')->firstOrFail();

    expect($execution->status)->toBe(AgentExecution::STATUS_FAILED)
        ->and($execution->failure_code)->toBe('provider.unavailable')
        ->and($execution->failure_reason)->toBe('The model provider could not complete the execution.')
        ->and(AgentDecision::query()->where('execution_id', $execution->getKey())->exists())->toBeFalse();
});
