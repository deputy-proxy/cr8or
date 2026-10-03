<?php

use App\Agents\OperationsAgent;
use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Mcp\Resources\EnterpriseContextResource;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ContinueAgentExecutionTool;
use App\Mcp\Tools\CreateAgentAssignmentTool;
use App\Mcp\Tools\CreateAgentExecutionTool;
use App\Mcp\Tools\CreateCampaignTool;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateEnterpriseContextTool;
use App\Mcp\Tools\CreateEnterpriseTool;
use App\Mcp\Tools\CreateKnowledgeIndexTool;
use App\Mcp\Tools\CreateKnowledgeItemTool;
use App\Mcp\Tools\CreateMarketingStrategyTool;
use App\Mcp\Tools\CreateMemoryTool;
use App\Mcp\Tools\CreateObjectiveTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\DelegateAgentTool;
use App\Mcp\Tools\ExecuteAgentTool;
use App\Mcp\Tools\GetAgentDelegationTool;
use App\Mcp\Tools\GetAgentDescriptorTool;
use App\Mcp\Tools\GetCampaignTool;
use App\Mcp\Tools\GetContentItemTool;
use App\Mcp\Tools\GetEnterpriseTool;
use App\Mcp\Tools\GetExecutionTool;
use App\Mcp\Tools\GetExpertDescriptorTool;
use App\Mcp\Tools\GetKnowledgeIndexTool;
use App\Mcp\Tools\GetKnowledgeUnitTool;
use App\Mcp\Tools\GetMarketingStrategyTool;
use App\Mcp\Tools\GetStrategyTool;
use App\Mcp\Tools\GetWorkItemTool;
use App\Mcp\Tools\ListAgentAssignmentsTool;
use App\Mcp\Tools\ListAgentDelegationsTool;
use App\Mcp\Tools\ListAgentDescriptorTool;
use App\Mcp\Tools\ListExecutionTool;
use App\Mcp\Tools\ListExpertDescriptorTool;
use App\Mcp\Tools\ListKnowledgeUnitsTool;
use App\Mcp\Tools\RetrieveKnowledgeTool;
use App\Mcp\Tools\RetrieveMemoryTool;
use App\Mcp\Tools\SubmitContentForReviewTool;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;
use ReflectionMethod;
use Tests\Support\Mcp\AuthenticatedTestServer;

beforeEach(function (): void {
    $this->seed([
        Database\Seeders\AgentDescriptorSeeder::class,
        Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

/** @return array<string, mixed> */
function e2eStructured(TestResponse $response): array
{
    $method = new ReflectionMethod($response, 'structuredContent');
    $method->setAccessible(true);
    $value = $method->invoke($response);

    return is_array($value) ? $value : [];
}

function e2eCall($server, string $tool, array $input, array &$report): array
{
    $response = $server->tool($tool, $input);
    $response->assertOk();
    $structured = e2eStructured($response);
    $report[] = ['tool' => $tool, 'input' => $input, 'result' => $structured];

    return $structured;
}

it('runs an interactive Agent execution through the MCP create surface without a ModelProvider', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::query()->firstOrCreate(['runtime_class' => App\Agents\CeoAgent::class], ['slug' => 'interactive-mcp-test-agent', 'enabled' => true]);
    $assignment = AgentAssignment::query()->create([
        'agent_descriptor_id' => $agent->getKey(),
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'enabled' => true,
        'status' => AgentAssignment::STATUS_READY,
    ]);

    $response = Cr8orServer::actingAs($user, 'api')->tool(CreateAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'prompt' => 'Create an interactive execution context.',
        'mode' => 'interactive',
        'idempotency_key' => 'mcp-interactive-mode-test',
    ]);

    $response->assertOk()->assertSee('mcp-interactive-mode-test');
    $execution = App\Models\AgentExecution::query()->where('idempotency_key', 'mcp-interactive-mode-test')->firstOrFail();

    expect($execution->mode)->toBe(App\Enums\AgentExecutionMode::INTERACTIVE)
        ->and($execution->status)->toBe(App\Models\AgentExecution::STATUS_WAITING_FOR_INPUT)
        ->and($execution->steps()->count())->toBe(0)
        ->and(Queue::pushedJobs())->toBeEmpty();
});

it('routes the governed agent.execute MCP entrypoint explicitly to interactive mode', function (): void {
    Queue::fake();
    Log::spy();
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::query()->firstOrCreate(['runtime_class' => OperationsAgent::class], ['slug' => 'governed-interactive-mcp-test-agent', 'enabled' => true]);
    $assignment = AgentAssignment::query()->create([
        'agent_descriptor_id' => $agent->getKey(),
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'enabled' => true,
        'status' => AgentAssignment::STATUS_READY,
    ]);

    $response = Cr8orServer::actingAs($user, 'api')->tool(ExecuteAgentTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'prompt' => 'Create an interactive execution through the governed capability.',
        'mode' => 'interactive',
        'capability_requests' => [[
            'step' => 1,
            'capability' => 'work.item.create',
            'expert_slug' => 'operations',
            'target_context' => ['enterprise_id' => $enterprise->getKey()],
            'input_payload' => ['name' => 'MCP interactive capability'],
            'idempotency_key' => 'mcp-interactive-boundary-step',
        ]],
        'correlation_id' => 'mcp-interactive-boundary',
        'idempotency_key' => 'mcp-interactive-boundary',
    ]);

    $response->assertOk()->assertSee('mcp-interactive-boundary');
    $execution = AgentExecution::query()->where('idempotency_key', 'mcp-interactive-boundary')->firstOrFail();

    expect($execution->mode)->toBe(App\Enums\AgentExecutionMode::INTERACTIVE)
        ->and($execution->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and(Queue::pushedJobs())->toBeEmpty();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'CR8OR Agent execution request received at MCP/application boundary.'
            && $context['execution_mode'] === 'interactive'
            && $context['correlation_id'] === 'mcp-interactive-boundary';
    });
});

it('runs E2E-TEST-20260928 unchanged through the CR8OR MCP surface', function (): void {
    Queue::fake();

    $actor = User::factory()->create(['name' => 'E2E Runtime Auditor']);
    $organization = Organization::factory()->create(['name' => 'E2E Test Organization']);
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $server = Cr8orServer::actingAs($actor, 'api');
    $report = [];

    $enterprise = e2eCall($server, CreateEnterpriseTool::class, [
        'organization_id' => $organization->getKey(),
        'name' => 'E2E-TEST-20260928 Enterprise',
        'slug' => 'e2e-test-20260928',
    ], $report)['result'];
    $enterpriseId = $enterprise['id'];

    e2eCall($server, CreateEnterpriseContextTool::class, [
        'enterprise_id' => $enterpriseId,
        'description' => 'Deterministic CR8OR runtime audit enterprise.',
        'industry' => 'Software',
        'business_model' => 'subscription',
        'target_market' => 'Small businesses',
        'geography' => 'Romania',
        'additional_context' => ['test_id' => 'E2E-TEST-20260928'],
    ], $report);

    $knowledgeItem = e2eCall($server, CreateKnowledgeItemTool::class, [
        'enterprise_id' => $enterpriseId,
        'title' => 'E2E Operating Knowledge',
        'type' => 'fact',
        'summary' => 'Approved operating facts for the E2E audit.',
        'content' => 'The enterprise targets small businesses. Campaign copy should be concise. Romanian is the primary market geography.',
    ], $report)['result'];
    $knowledgeItemId = $knowledgeItem['id'];

    $index = e2eCall($server, CreateKnowledgeIndexTool::class, [
        'enterprise_id' => $enterpriseId,
        'knowledge_item_id' => $knowledgeItemId,
        'correlation_id' => 'e2e-knowledge-index',
    ], $report)['result'];
    $indexId = $index['indexes'][0]['id'] ?? null;

    $units = e2eCall($server, ListKnowledgeUnitsTool::class, [
        'enterprise_id' => $enterpriseId,
        'knowledge_item_id' => $knowledgeItemId,
        'per_page' => 50,
    ], $report)['result']['items'] ?? [];
    expect($units)->toHaveCount(1);

    $secondKnowledgeItem = e2eCall($server, CreateKnowledgeItemTool::class, [
        'enterprise_id' => $enterpriseId,
        'title' => 'E2E Secondary Knowledge',
        'type' => 'fact',
        'summary' => 'Second authoritative knowledge record for the audit.',
        'content' => 'Secondary approved knowledge for the E2E workflow.',
    ], $report)['result'];
    $secondKnowledgeItemId = $secondKnowledgeItem['id'];
    e2eCall($server, CreateKnowledgeIndexTool::class, [
        'enterprise_id' => $enterpriseId,
        'knowledge_item_id' => $secondKnowledgeItemId,
        'correlation_id' => 'e2e-knowledge-index-2',
    ], $report);

    $units = e2eCall($server, ListKnowledgeUnitsTool::class, [
        'enterprise_id' => $enterpriseId,
        'per_page' => 50,
    ], $report)['result']['items'] ?? [];
    expect($units)->toHaveCount(2);

    e2eCall($server, GetKnowledgeIndexTool::class, [
        'enterprise_id' => $enterpriseId,
        'knowledge_index_id' => $indexId,
    ], $report);
    e2eCall($server, GetKnowledgeUnitTool::class, [
        'enterprise_id' => $enterpriseId,
        'knowledge_unit_id' => $units[0]['id'],
    ], $report);

    $knowledge = e2eCall($server, RetrieveKnowledgeTool::class, [
        'enterprise_id' => $enterpriseId,
        'query' => 'concise campaign copy for Romanian small businesses',
        'mode' => 'hybrid',
        'limit' => 10,
        'correlation_id' => 'e2e-knowledge-retrieval',
    ], $report)['result'];
    expect($knowledge['items'])->toHaveCount(2);

    $marketingAgent = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $productAgent = AgentDescriptor::query()->where('slug', 'product')->firstOrFail();

    $marketingAssignment = e2eCall($server, CreateAgentAssignmentTool::class, [
        'enterprise_id' => $enterpriseId,
        'agent_descriptor_id' => $marketingAgent->getKey(),
        'objective' => 'Coordinate the E2E governed marketing workflow.',
        'requirements' => ['test_id' => 'E2E-TEST-20260928'],
        'context' => ['knowledge_item_id' => $knowledgeItemId],
        'status' => 'ready',
        'idempotency_key' => 'e2e-marketing-assignment',
    ], $report)['result'];
    $marketingAssignmentId = $marketingAssignment['id'];

    $productAssignment = e2eCall($server, CreateAgentAssignmentTool::class, [
        'enterprise_id' => $enterpriseId,
        'agent_descriptor_id' => $productAgent->getKey(),
        'objective' => 'Provide product planning input to the marketing workflow.',
        'status' => 'ready',
        'idempotency_key' => 'e2e-product-assignment',
    ], $report)['result'];
    $productAssignmentId = $productAssignment['id'];

    foreach (['agent.delegate', 'marketing.plan', 'strategy.create'] as $capability) {
    }
    foreach (['strategy.create', 'strategy.update', 'work.item.create', 'work.item.update'] as $capability) {
    }

    $objective = e2eCall($server, CreateObjectiveTool::class, [
        'enterprise_id' => $enterpriseId,
        'name' => 'E2E Product Growth Objective',
        'description' => 'Support the E2E workflow.',
    ], $report)['result'];
    $strategyResponse = e2eCall($server, CreateStrategyTool::class, [
        'objective_id' => $objective['id'],
        'name' => 'E2E Product Strategy',
        'description' => 'Strategy used by the Product Expert.',
    ], $report)['result'];
    $strategyId = $strategyResponse['id'];

    $marketingStrategyResponse = e2eCall($server, CreateMarketingStrategyTool::class, [
        'enterprise_id' => $enterpriseId,
        'name' => 'E2E Marketing Strategy',
        'description' => 'Strategy used by the runtime audit.',
    ], $report)['result'];
    $marketingStrategyId = $marketingStrategyResponse['id'];

    $workItem = e2eCall($server, CreateWorkItemTool::class, [
        'enterprise_id' => $enterpriseId,
        'name' => 'E2E Work Item',
        'description' => 'Work context for the Product Expert.',
        'status' => 'planned',
    ], $report)['result'];
    $workItemId = $workItem['id'];

    $memorySourceExecution = AgentExecution::factory()->forAssignment(AgentAssignment::query()->findOrFail($marketingAssignmentId))->create([
        'actor_id' => $actor->getKey(),
        'status' => AgentExecution::STATUS_COMPLETED,
        'provider' => 'fake',
        'external_execution_id' => 'e2e-memory-source',
        'correlation_id' => 'e2e-memory-source',
        'completed_at' => now(),
        'last_result' => ['text' => 'Memory source fixture completed.'],
    ]);
    $memorySourceExecutionId = $memorySourceExecution->getKey();

    foreach (range(1, 4) as $number) {
        e2eCall($server, CreateMemoryTool::class, [
            'enterprise_id' => $enterpriseId,
            'type' => $number % 2 === 0 ? 'semantic' : 'episodic',
            'source_execution_id' => $memorySourceExecutionId,
            'agent_descriptor_id' => $marketingAgent->getKey(),
            'topic' => 'E2E runtime audit',
            'objective' => 'Preserve E2E memory evidence.',
            'action' => 'Recorded durable runtime evidence.',
            'result' => 'Memory record persisted.',
            'outcome' => 'Evidence is available for retrieval.',
            'statement' => 'The enterprise uses governed E2E evidence '.$number.'.',
            'confidence' => 0.9,
        ], $report);
    }

    $memory = e2eCall($server, RetrieveMemoryTool::class, [
        'enterprise_id' => $enterpriseId,
        'agent_descriptor_id' => $marketingAgent->getKey(),
        'semantic_limit' => 10,
        'episodic_limit' => 10,
        'correlation_id' => 'e2e-memory-retrieval',
    ], $report)['result'];
    expect(count($memory['semantic']) + count($memory['episodic']))->toBe(4);
    e2eCall($server, GetExecutionTool::class, ['enterprise_id' => $enterpriseId, 'agent_execution_id' => $memorySourceExecutionId], $report);

    $parentExecution = e2eCall($server, CreateAgentExecutionTool::class, [
        'enterprise_id' => $enterpriseId,
        'agent_assignment_id' => $marketingAssignmentId,
        'prompt' => 'Coordinate the E2E governed marketing workflow.',
        'mode' => 'interactive',
        'capability_requests' => [[
            'capability' => 'strategy.create',
            'expert_slug' => 'strategy',
            'target_context' => ['objective_id' => $objective['id']],
            'input_payload' => ['objective_id' => $objective['id'], 'name' => 'E2E Interactive Strategy'],
            'idempotency_key' => 'e2e-interactive-strategy',
        ]],
        'expert_slugs' => ['marketing', 'copywriting'],
        'correlation_id' => 'e2e-parent-execution',
        'idempotency_key' => 'e2e-parent-execution',
    ], $report)['result'];
    $parentExecutionId = $parentExecution['execution']['id'];

    $fakeProvider = new FakeModelProvider(function ($request): ModelResult {
        expect($request->context)->toHaveKeys(['enterprise', 'strategy', 'work', 'knowledge', 'memory', 'experts']);
        expect($request->context['experts']['results'])->toHaveCount(1);

        return new ModelResult(
            text: 'Product planning input prepared.',
            structured: [
                'answer' => 'Product planning input prepared.',
                'decision_title' => 'E2E product planning',
                'decision_summary' => 'The Product Expert provided governed planning input.',
                'decision_rationale' => 'The runtime used authorized Enterprise, Knowledge, Memory, Strategy and Work context.',
                'termination' => 'completed',
            ],
            provider: 'fake',
            model: 'e2e-test',
            invocationId: 'e2e-child-execution',
            correlationId: $request->correlationId,
        );
    });
    app()->instance(ModelProvider::class, $fakeProvider);

    $delegation = e2eCall($server, DelegateAgentTool::class, [
        'source_agent_assignment_id' => $marketingAssignmentId,
        'target_agent_slug' => 'product',
        'capability' => 'strategy.create',
        'prompt' => 'Provide product planning input for the E2E workflow.',
        'target_context' => ['strategy_id' => $strategyId],
        'expert_slugs' => ['product'],
        'parent_agent_execution_id' => $parentExecutionId,
        'correlation_id' => 'e2e-delegation',
        'idempotency_key' => 'e2e-delegation',
    ], $report)['result'];
    $delegationId = $delegation['delegation_id'];
    $childExecutionId = $delegation['execution_id'];

    expect($delegation['status'])->toBe('succeeded')
        ->and($childExecutionId)->not->toBeNull();

    $strategyResponse = e2eCall($server, GetStrategyTool::class, ['id' => $strategyId], $report);
    unset($strategyResponse);
    e2eCall($server, GetWorkItemTool::class, ['id' => $workItemId], $report);
    e2eCall($server, GetAgentDelegationTool::class, ['enterprise_id' => $enterpriseId, 'agent_delegation_id' => $delegationId], $report);
    e2eCall($server, ListAgentDelegationsTool::class, ['enterprise_id' => $enterpriseId, 'status' => 'succeeded'], $report);
    e2eCall($server, GetExecutionTool::class, ['enterprise_id' => $enterpriseId, 'agent_execution_id' => $parentExecutionId], $report);
    $child = e2eCall($server, GetExecutionTool::class, ['enterprise_id' => $enterpriseId, 'agent_execution_id' => $childExecutionId], $report)['result'];
    expect(json_encode($child))->toContain('Product')
        ->and($child['execution']['result']['expert_results'])->toHaveCount(1);

    $campaign = e2eCall($server, CreateCampaignTool::class, [
        'enterprise_id' => $enterpriseId,
        'marketing_strategy_id' => $marketingStrategyId,
        'name' => 'E2E Campaign',
        'description' => 'Campaign for the runtime audit.',
    ], $report)['result'];

    $contentBody = sprintf(
        'Romanian small-business campaign. Knowledge: %s. Memory: %s. Execution: %s. Product result: %s.',
        substr(json_encode($knowledge, JSON_THROW_ON_ERROR), 0, 500),
        substr(json_encode($memory, JSON_THROW_ON_ERROR), 0, 500),
        $childExecutionId,
        substr((string) ($child['last_result']['structured']['decision_summary'] ?? 'Product planning input prepared.'), 0, 300),
    );

    $content = e2eCall($server, CreateContentItemTool::class, [
        'enterprise_id' => $enterpriseId,
        'campaign_id' => $campaign['id'],
        'title' => 'E2E-TEST-20260928 Content',
        'body' => $contentBody,
    ], $report)['result'];

    expect($content['status'])->toBe('draft');
    $review = e2eCall($server, SubmitContentForReviewTool::class, ['content_item_id' => $content['id']], $report)['result'];
    expect($review['status'])->toBe('in_review');

    $server->tool(CreateEnterpriseTool::class, [
        'organization_id' => $organization->getKey(),
        'name' => 'E2E-TEST-20260928 Enterprise',
        'slug' => 'e2e-test-20260928',
    ])->assertHasErrors();

    $foreign = Enterprise::factory()->create();
    $server->tool(GetEnterpriseTool::class, ['id' => $foreign->getKey()])->assertHasErrors();
    $server->tool(CreateKnowledgeIndexTool::class, ['enterprise_id' => $enterpriseId, 'knowledge_item_id' => App\Models\KnowledgeItem::factory()->create(['enterprise_id' => $foreign])->getKey()])->assertHasErrors();
    $server->tool(CreateAgentExecutionTool::class, ['enterprise_id' => $enterpriseId, 'agent_assignment_id' => $marketingAssignmentId, 'prompt' => ''])->assertHasErrors();
    $marketingAssignmentModel = AgentAssignment::query()->findOrFail($marketingAssignmentId);
    $marketingAssignmentModel->status = AgentAssignment::STATUS_DRAFT;
    $marketingAssignmentModel->save();
    $server->tool(\App\Mcp\Tools\TransitionAgentAssignmentTool::class, ['enterprise_id' => $enterpriseId, 'agent_assignment_id' => $marketingAssignmentId, 'status' => 'running'])->assertHasErrors();

    e2eCall($server, GetEnterpriseTool::class, ['id' => $enterpriseId], $report);
    $context = AuthenticatedTestServer::actingAs($actor, 'api')->resource(EnterpriseContextResource::class, ['enterprise' => $enterpriseId]);
    $context->assertOk()->assertSee('E2E-TEST-20260928');
    $report[] = ['resource' => EnterpriseContextResource::class, 'input' => ['enterprise' => $enterpriseId], 'result' => e2eStructured($context)];
    e2eCall($server, ListAgentAssignmentsTool::class, ['enterprise_id' => $enterpriseId, 'limit' => 50], $report);
    e2eCall($server, ListExecutionTool::class, ['enterprise_id' => $enterpriseId, 'limit' => 50], $report);
    e2eCall($server, GetCampaignTool::class, ['id' => $campaign['id']], $report);
    e2eCall($server, GetMarketingStrategyTool::class, ['id' => $marketingStrategyId], $report);
    e2eCall($server, GetContentItemTool::class, ['id' => $content['id']], $report);
    e2eCall($server, GetAgentDescriptorTool::class, ['id' => $marketingAgent->getKey()], $report);
    $productExpert = ExpertDescriptor::query()->where('slug', 'product')->firstOrFail();
    e2eCall($server, GetExpertDescriptorTool::class, ['id' => $productExpert->getKey()], $report);
    e2eCall($server, ListAgentDescriptorTool::class, ['per_page' => 50], $report);
    e2eCall($server, ListExpertDescriptorTool::class, ['per_page' => 50], $report);

    $reportDocument = [
        'test_id' => 'E2E-TEST-20260928',
        'success' => true,
        'enterprise_id' => $enterpriseId,
        'graph' => [
            'enterprise' => $enterpriseId,
            'enterprise_context' => true,
            'knowledge_item' => $knowledgeItemId,
            'knowledge_index' => $indexId,
            'knowledge_units' => array_column($units, 'id'),
            'memory' => $memory,
            'marketing_assignment' => $marketingAssignmentId,
            'parent_execution' => $parentExecutionId,
            'delegation' => $delegationId,
            'child_execution' => $childExecutionId,
            'campaign' => $campaign['id'],
            'content' => $content['id'],
            'content_status' => $review['status'],
        ],
        'assertions' => [
            'knowledge_retrieved' => true,
            'memory_retrieved' => true,
            'expert_invoked' => true,
            'delegation_succeeded' => true,
            'content_reached_human_review' => true,
            'automatic_publication' => false,
            'negative_cases_safe' => true,
        ],
        'mcp_interactions' => $report,
    ];

    $path = storage_path('app/e2e/E2E-TEST-20260928.json');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($reportDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    fwrite(STDOUT, PHP_EOL.json_encode($reportDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

    expect($review['status'])->toBe('in_review');
});

it('runs a multi-step interactive continuation E2E without invoking a ModelProvider', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $enterprise = Enterprise::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $enterprise->organization_id]);
    $agent = AgentDescriptor::query()->firstOrCreate(['runtime_class' => OperationsAgent::class], ['slug' => 'continuous-interactive-e2e-agent', 'enabled' => true]);
    $assignment = AgentAssignment::query()->create([
        'agent_descriptor_id' => $agent->getKey(),
        'organization_id' => $enterprise->organization_id,
        'enterprise_id' => $enterprise->getKey(),
        'enabled' => true,
        'status' => AgentAssignment::STATUS_READY,
    ]);

    $providerCalls = 0;
    app()->bind(ModelProvider::class, function () use (&$providerCalls): ModelProvider {
        return new FakeModelProvider(function (App\AI\Data\ModelRequest $request) use (&$providerCalls): ModelResult {
            $providerCalls++;
            throw new RuntimeException('Interactive E2E invoked a ModelProvider unexpectedly.');
        });
    });

    $server = Cr8orServer::actingAs($user, 'api');
    $start = $server->tool(CreateAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_assignment_id' => $assignment->getKey(),
        'prompt' => 'Complete a two-step governed interactive workflow.',
        'mode' => 'interactive',
        'idempotency_key' => 'continuous-interactive-e2e',
    ]);
    $start->assertOk()->assertSee('mcp_agent_continue')->assertSee('waiting_for_input');

    $execution = AgentExecution::query()->where('idempotency_key', 'continuous-interactive-e2e')->firstOrFail();
    $stepOne = $server->tool(ContinueAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'expected_step' => 1,
        'idempotency_key' => 'continuous-interactive-e2e-step-1',
        'reasoning' => 'Use the Operations Expert to create the first durable work item.',
        'capability_requests' => [[
            'capability' => 'work.item.create',
            'expert_slug' => 'operations',
            'step' => 1,
            'target_context' => ['enterprise_id' => $enterprise->getKey()],
            'input_payload' => ['enterprise_id' => $enterprise->getKey(), 'name' => 'Interactive E2E work item'],
            'idempotency_key' => 'continuous-interactive-e2e-domain-1',
        ]],
        'delegation_requests' => [],
        'termination' => 'continue',
        'termination_reason' => 'The first governed operation completed; continue to verification.',
    ]);
    $stepOne->assertOk()->assertSee('reasoning')->assertSee('mcp_agent_continue');

    expect(App\Models\WorkItem::query()->where('name', 'Interactive E2E work item')->count())->toBe(1)
        ->and($execution->refresh()->current_step)->toBe(1)
        ->and($execution->status)->toBe(AgentExecution::STATUS_REASONING);

    $server->tool(ContinueAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'expected_step' => 1,
        'idempotency_key' => 'continuous-interactive-e2e-step-1',
        'reasoning' => 'Duplicate delivery of the same reasoning result.',
        'capability_requests' => [],
        'delegation_requests' => [],
        'termination' => 'completed',
        'termination_reason' => 'ignored duplicate',
    ])->assertOk();

    expect(App\Models\WorkItem::query()->where('name', 'Interactive E2E work item')->count())->toBe(1)
        ->and($execution->refresh()->status)->toBe(AgentExecution::STATUS_REASONING);

    $server->tool(ContinueAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'expected_step' => 2,
        'idempotency_key' => 'continuous-interactive-e2e-step-2',
        'reasoning' => 'The authoritative work item exists, so the workflow can terminate.',
        'capability_requests' => [],
        'delegation_requests' => [],
        'termination' => 'completed',
        'termination_reason' => 'Interactive workflow completed.',
    ])->assertOk()->assertSee('completed');

    expect($execution->refresh()->status)->toBe(AgentExecution::STATUS_COMPLETED)
        ->and($execution->current_step)->toBe(2)
        ->and($execution->steps()->count())->toBe(2)
        ->and(Queue::pushedJobs())->toBeEmpty()
        ->and($providerCalls)->toBe(0);

    $server->tool(ContinueAgentExecutionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'agent_execution_id' => $execution->getKey(),
        'expected_step' => 1,
        'idempotency_key' => 'continuous-interactive-e2e-stale',
        'reasoning' => 'Stale continuation.',
        'capability_requests' => [],
        'delegation_requests' => [],
        'termination' => 'completed',
    ])->assertHasErrors();
});