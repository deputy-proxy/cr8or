<?php

use App\Agents\OperationsAgent;
use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityInvocationRequest;
use App\Data\Integrations\IntegrationResultEnvelope;
use App\Experts\OperationsExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\UpdateWorkItemTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\CommandWebhookDelivery;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkItem;
use App\Services\CapabilityInvocationService;
use App\Services\IntegrationResultService;
use App\Services\WorkflowExecutionService;
use App\Services\WorkflowVersionService;
use Carbon\CarbonImmutable;

function commandHeaders(string $secret, string $body, int $timestamp, string $key): array
{
    return ['X-CR8OR-Command-Key' => $key, 'X-CR8OR-Command-Timestamp' => (string) $timestamp, 'X-CR8OR-Command-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
}

function phaseE2EAgentContext(User $actor, Enterprise $enterprise): array
{
    $descriptor = AgentDescriptor::factory()->forRuntimeClass(OperationsAgent::class)->create(['slug' => 'operations']);
    ExpertDescriptor::query()->updateOrCreate(['slug' => 'operations'], ['runtime_class' => OperationsExpert::class, 'enabled' => true]);
    $assignment = AgentAssignment::factory()->forEnterprise($enterprise)->create(['agent_descriptor_id' => $descriptor->getKey()]);
    $execution = AgentExecution::factory()->forAssignment($assignment)->create(['actor_id' => $actor->getKey(), 'actor_name' => $actor->name]);

    return [$assignment, $execution];
}

function phaseE2EActor(Enterprise $enterprise): User
{
    $actor = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $enterprise->organization_id,
    ]);

    return $actor;
}

it('proves the representative work item Operation is shared by MCP, Agent, Workflow and Command Webhook entry points', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = phaseE2EActor($enterprise);

    $mcpItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'name' => 'mcp-before']);
    $agentItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'name' => 'agent-before']);
    $workflowItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'name' => 'workflow-before']);
    $commandItem = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'name' => 'command-before']);

    $direct = Cr8orServer::actingAs($actor, 'api')
        ->tool(UpdateWorkItemTool::class, [
            'work_item_id' => $mcpItem->getKey(),
            'name' => 'mcp-after',
        ]);

    $direct->assertOk();

    [$assignment, $execution] = phaseE2EAgentContext($actor, $enterprise);

    $agentResult = app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
        capability: 'work.item.update',
        actor: $actor,
        enterprise: $enterprise,
        assignment: $assignment,
        execution: $execution,
        targetContext: ['work_item_id' => $agentItem->getKey()],
        inputPayload: ['name' => 'agent-after'],
        expertSlug: 'operations',
        correlationId: $execution->correlation_id,
        idempotencyKey: 'phase-e2e-agent-1',
    ));

    $workflow = Workflow::factory()->create(['enterprise_id' => $enterprise]);
    WorkflowStage::factory()->create(['workflow_id' => $workflow->getKey(), 'key' => 'update-work', 'name' => 'Update work', 'sequence' => 1, 'dependencies' => [], 'expert_slugs' => ['operations'], 'capability_slugs' => ['work.item.update'], 'input_contract' => ['required' => ['work_item_id', 'name']], 'output_contract' => []]);
    $version = app(WorkflowVersionService::class)->publish($workflow, $actor, 'phase-e2e-publish-'.$workflow->getKey());
    $workflowResult = app(WorkflowExecutionService::class)->start($actor, $version, ['work_item_id' => $workflowItem->getKey(), 'name' => 'workflow-after'], 'phase-e2e-workflow-1', 'phase-e2e-workflow');

    config()->set('services.command_webhooks.credentials.phase-e2e', [
        'secret' => 'phase-e2e-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'phase-e2e-command-1',
        'correlation_id' => 'phase-e2e-command',
        'input' => [
            'work_item_id' => $commandItem->getKey(),
            'name' => 'command-after',
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $command = $this->withHeaders(commandHeaders(
        'phase-e2e-secret',
        $body,
        now()->timestamp,
        'phase-e2e',
    ))->postJson('/commands/work.item.update', $payload);

    $command->assertOk();

    expect($mcpItem->refresh()->name)->toBe('mcp-after')
        ->and($agentItem->refresh()->name)->toBe('agent-after')
        ->and($workflowItem->refresh()->name)->toBe('workflow-after')
        ->and($commandItem->refresh()->name)->toBe('command-after')
        ->and($agentResult['capability'])->toBe('work.item.update')
        ->and($workflowResult->status)->toBe('completed')
        ->and(CommandWebhookDelivery::query()->count())->toBe(1);
});

it('preserves the integration reconciliation exception outside the business Capability boundary', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = phaseE2EActor($enterprise);

    $connection = IntegrationConnection::query()->create([
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'canva',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
    $job = IntegrationJob::query()->create([
        'integration_connection_id' => $connection->id,
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'canva',
        'operation' => 'design.create',
        'idempotency_key' => 'phase-e2e-integration-job',
        'status' => IntegrationJob::STATUS_PENDING,
        'external_job_id' => 'phase-e2e-external-job',
        'correlation_id' => 'phase-e2e-integration',
        'attempts' => 1,
    ]);

    $result = app(IntegrationResultService::class)->ingest(
        'canva',
        'webhook',
        new IntegrationResultEnvelope(
            'phase-e2e-external-job', 'phase-e2e-result', 'succeeded',
            'phase-e2e-integration', 'phase-e2e-delivery',
            CarbonImmutable::now(), ['provider_status' => 'succeeded'], null, null,
        ),
    );

    expect($result->processing_status)->toBe(IntegrationResult::PROCESSING_APPLIED)
        ->and($job->refresh()->status)->toBe(IntegrationJob::STATUS_SUCCEEDED);
});

it('keeps lifecycle MCP Tools outside the business Capability registry', function (): void {
    $registry = app(CapabilityRegistry::class);

    foreach ($registry->all() as $definition) {
        if ($definition->category === 'lifecycle') {
            expect($definition->tool)->toStartWith('mcp_');
        }
    }
});