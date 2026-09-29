<?php

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentDescriptor;
use App\Models\AgentEpisodicMemory;
use App\Models\AgentExecution;
use App\Models\AgentSemanticMemory;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\KnowledgeIndexRecord;
use App\Models\KnowledgeIndexUnit;
use App\Models\KnowledgeItem;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Strategy;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\CapabilityInvocationService;
use Illuminate\Support\Facades\File;
use Throwable;

beforeEach(function (): void {
    $this->seed([
        Database\Seeders\AgentDescriptorSeeder::class,
        Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function capabilityE2E(
    User $actor,
    ?Enterprise $enterprise,
    string $capability,
    array $input,
    array &$report,
    ?string $correlationId = null,
    ?string $idempotencyKey = null,
): array {
    $request = new CapabilityInvocationRequest(
        capability: $capability,
        actor: $actor,
        enterprise: $enterprise,
        inputPayload: $input,
        correlationId: $correlationId,
        idempotencyKey: $idempotencyKey,
    );

    $result = app(CapabilityInvocationService::class)->invoke($request);
    $report[] = [
        'capability' => $capability,
        'operation' => $result['provenance']['operation'] ?? null,
        'status' => $result['status'] ?? null,
        'input' => $input,
        'result' => $result['result'] ?? null,
        'provenance' => $result['provenance'] ?? [],
    ];

    return is_array($result['result'] ?? null) ? $result['result'] : ['value' => $result['result'] ?? null];
}

/**
 * @param  array<string, mixed>  $report
 * @param  array<string, mixed>  $input
 */
function capabilityE2EFailure(
    User $actor,
    Enterprise $enterprise,
    string $capability,
    array $input,
    array &$report,
): Throwable {
    try {
        app(CapabilityInvocationService::class)->invoke(new CapabilityInvocationRequest(
            capability: $capability,
            actor: $actor,
            enterprise: $enterprise,
            inputPayload: $input,
        ));

        throw new RuntimeException('Expected Capability failure did not occur.');
    } catch (Throwable $exception) {
        if ($exception instanceof RuntimeException && $exception->getMessage() === 'Expected Capability failure did not occur.') {
            throw $exception;
        }

        $report[] = [
            'capability' => $capability,
            'operation' => app(CapabilityRegistry::class)->resolve($capability)->operation,
            'status' => 'failed',
            'input' => $input,
            'error' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        ];

        return $exception;
    }
}

it('runs E2E-CAPABILITY-20260928 entirely through the application Capability boundary', function (): void {
    $report = [
        'capabilities' => [],
        'test_id' => 'E2E-CAPABILITY-20260928',
        'constraints' => [
            'application_entry_point' => 'CapabilityInvocationService',
            'mcp_tools_used' => false,
            'railway_sandbox_used_by_test' => false,
            'direct_business_mutations' => false,
        ],
    ];

    $actor = User::factory()->create(['name' => 'Capability E2E Auditor']);
    $organization = Organization::factory()->create(['name' => 'Capability E2E Organization']);
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterpriseResult = capabilityE2E(
        $actor,
        null,
        'enterprise.create',
        [
            'organization_id' => $organization->getKey(),
            'name' => 'E2E-CAPABILITY-20260928 Enterprise',
            'slug' => 'e2e-capability-20260928',
        ],
        $report['capabilities'],
        'e2e-capability-enterprise',
        'e2e-capability-enterprise',
    );

    $enterprise = Enterprise::query()->findOrFail($enterpriseResult['id']);

    capabilityE2E(
        $actor,
        $enterprise,
        'enterprise.context.create',
        [
            'description' => 'Deterministic capability-native runtime audit enterprise.',
            'industry' => 'Software',
            'business_model' => 'subscription',
            'target_market' => 'Small businesses',
            'geography' => 'Romania',
            'additional_context' => ['test_id' => 'E2E-CAPABILITY-20260928'],
        ],
        $report['capabilities'],
        'e2e-capability-context',
    );

    $knowledge = capabilityE2E(
        $actor,
        $enterprise,
        'knowledge.item.create',
        [
            'title' => 'E2E Capability Knowledge',
            'type' => 'fact',
            'summary' => 'Authoritative facts for the capability-native workflow.',
            'content' => 'The enterprise targets small businesses. Romanian is the primary market geography. Campaign copy should be concise.',
        ],
        $report['capabilities'],
        'e2e-capability-knowledge-create',
        'e2e-capability-knowledge-create',
    );
    $knowledgeItemId = $knowledge['id'];

    $indexed = capabilityE2E(
        $actor,
        $enterprise,
        'knowledge.index.create',
        ['knowledge_item_id' => $knowledgeItemId],
        $report['capabilities'],
        'e2e-capability-knowledge-index',
        'e2e-capability-knowledge-index',
    );
    expect($indexed['indexed_units'])->toBeGreaterThan(0);

    $retrievedKnowledge = capabilityE2E(
        $actor,
        $enterprise,
        'knowledge.retrieve',
        [
            'query' => 'Romanian small businesses concise campaign copy',
            'mode' => 'hybrid',
            'limit' => 10,
        ],
        $report['capabilities'],
        'e2e-capability-knowledge-retrieve',
    );
    expect($retrievedKnowledge['items'])->not->toBeEmpty();

    $objective = capabilityE2E(
        $actor,
        $enterprise,
        'marketing.objective.create',
        [
            'name' => 'E2E Capability Growth Objective',
            'description' => 'Support the capability-native workflow.',
        ],
        $report['capabilities'],
    );

    $strategy = capabilityE2E(
        $actor,
        $enterprise,
        'strategy.create',
        [
            'objective_id' => $objective['id'],
            'name' => 'E2E Capability Strategy',
            'description' => 'Strategy used by the capability-native runtime.',
        ],
        $report['capabilities'],
    );

    $work = capabilityE2E(
        $actor,
        $enterprise,
        'work.item.create',
        [
            'name' => 'E2E Capability Work Item',
            'description' => 'Work context for the Agent runtime.',
            'status' => 'planned',
        ],
        $report['capabilities'],
    );

    $businessAnalysis = capabilityE2E(
        $actor,
        $enterprise,
        'business.analysis',
        [
            'target_context' => [
                'strategy_id' => $strategy['id'],
                'work_item_id' => $work['id'],
            ],
        ],
        $report['capabilities'],
    );
    expect($businessAnalysis['capability'])->toBe('business.analysis');

    $marketing = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $product = AgentDescriptor::query()->where('slug', 'product')->firstOrFail();

    $marketingAssignment = capabilityE2E(
        $actor,
        $enterprise,
        'agent.assignment.create',
        [
            'agent_descriptor_id' => $marketing->getKey(),
            'objective' => 'Coordinate the governed marketing workflow.',
            'requirements' => ['test_id' => 'E2E-CAPABILITY-20260928'],
            'context' => ['knowledge_item_id' => $knowledgeItemId],
        ],
        $report['capabilities'],
        'e2e-capability-assignment-marketing',
        'e2e-capability-assignment-marketing',
    );
    $marketingAssignmentId = $marketingAssignment['id'];

    capabilityE2E(
        $actor,
        $enterprise,
        'agent.assignment.transition',
        [
            'agent_assignment_id' => $marketingAssignmentId,
            'status' => AgentAssignment::STATUS_READY,
        ],
        $report['capabilities'],
    );

    $productAssignment = capabilityE2E(
        $actor,
        $enterprise,
        'agent.assignment.create',
        [
            'agent_descriptor_id' => $product->getKey(),
            'objective' => 'Provide governed product planning input.',
        ],
        $report['capabilities'],
        'e2e-capability-assignment-product',
        'e2e-capability-assignment-product',
    );
    $productAssignmentId = $productAssignment['id'];

    capabilityE2E(
        $actor,
        $enterprise,
        'agent.assignment.transition',
        [
            'agent_assignment_id' => $productAssignmentId,
            'status' => AgentAssignment::STATUS_READY,
        ],
        $report['capabilities'],
    );
    foreach (['marketing.plan', 'marketing.strategy.create', 'marketing.content.create', 'marketing.content.update', 'marketing.content.review', 'marketing.content.publication-ready', 'strategy.create', 'strategy.update'] as $capability) {
    }
    foreach (['strategy.create', 'strategy.update', 'work.item.create', 'work.item.update'] as $capability) {
    }

    $provider = new FakeModelProvider(function ($request): ModelResult {
        return new ModelResult(
            text: 'Capability-native Agent reasoning completed.',
            structured: [
                'answer' => 'Capability-native Agent reasoning completed.',
                'decision_title' => 'Proceed',
                'decision_summary' => 'Persisted context supports the requested workflow.',
                'decision_rationale' => 'Enterprise, Knowledge, Strategy and Work context were available through the governed runtime.',
                'capability_requests' => [],
            ],
            provider: 'fake',
            model: 'e2e-capability',
            invocationId: 'e2e-capability-model-'.$request->correlationId,
            correlationId: $request->correlationId,
        );
    });
    app()->instance(ModelProvider::class, $provider);

    $execution = capabilityE2E(
        $actor,
        $enterprise,
        'agent.execute',
        [
            'agent_assignment_id' => $marketingAssignmentId,
            'prompt' => 'Coordinate the E2E capability-native marketing workflow using the persisted Enterprise, Knowledge, Strategy and Work context.',
            'mode' => 'autonomous',
            'expert_slugs' => ['marketing', 'strategy', 'copywriting'],
        ],
        $report['capabilities'],
        'e2e-capability-parent-execution',
        'e2e-capability-parent-execution',
    );
    $parentExecutionId = $execution['execution']['id'];
    $parentExecution = AgentExecution::query()->findOrFail($parentExecutionId);

    expect($parentExecution->status)->toBe(AgentExecution::STATUS_SUCCEEDED)
        ->and($parentExecution->agent_assignment_id)->toBe($marketingAssignmentId)
        ->and($parentExecution->last_result['expert_results'])->not->toBeEmpty();

    $memory = capabilityE2E(
        $actor,
        $enterprise,
        'memory.record',
        [
            'persist' => true,
            'type' => 'episodic',
            'source_execution_id' => $parentExecutionId,
            'agent_descriptor_id' => $marketing->getKey(),
            'objective' => 'Preserve capability-native E2E evidence.',
            'action' => 'Executed the governed Agent workflow.',
            'result' => 'Agent execution completed with Expert-backed reasoning.',
            'outcome' => 'The persisted runtime graph is attributable.',
            'topic' => 'E2E capability workflow',
        ],
        $report['capabilities'],
        'e2e-capability-memory-record',
    );
    expect($memory)->not->toBeEmpty();

    $retrievedMemory = capabilityE2E(
        $actor,
        $enterprise,
        'memory.retrieve',
        [
            'agent_descriptor_id' => $marketing->getKey(),
            'topic' => 'E2E capability workflow',
            'episodic_limit' => 10,
            'semantic_limit' => 10,
        ],
        $report['capabilities'],
        'e2e-capability-memory-retrieve',
    );
    expect($retrievedMemory['episodic'])->not->toBeEmpty();

    $delegation = capabilityE2E(
        $actor,
        $enterprise,
        'agent.delegate',
        [
            'source_agent_assignment_id' => $marketingAssignmentId,
            'target_agent_slug' => 'product',
            'capability' => 'strategy.create',
            'prompt' => 'Provide product planning input for the E2E workflow.',
            'target_context' => ['strategy_id' => $strategy['id']],
            'expert_slugs' => ['product'],
            'parent_agent_execution_id' => $parentExecutionId,
            'idempotency_key' => 'e2e-capability-delegation',
        ],
        $report['capabilities'],
        'e2e-capability-delegation',
        'e2e-capability-delegation',
    );
    $delegationResponse = $delegation['value'];
    expect($delegationResponse->delegation->status)->toBe(AgentDelegation::STATUS_SUCCEEDED);

    $campaignStrategy = capabilityE2E(
        $actor,
        $enterprise,
        'marketing.strategy.create',
        [
            'name' => 'E2E Campaign Strategy',
            'description' => 'Marketing strategy for the capability-native content workflow.',
        ],
        $report['capabilities'],
    );

    $campaign = capabilityE2E(
        $actor,
        $enterprise,
        'marketing.campaign.create',
        [
            'marketing_strategy_id' => $campaignStrategy['id'],
            'name' => 'E2E Capability Campaign',
            'description' => 'Campaign created entirely through Capabilities.',
        ],
        $report['capabilities'],
    );

    $content = capabilityE2E(
        $actor,
        $enterprise,
        'marketing.content.create',
        [
            'campaign_id' => $campaign['id'],
            'title' => 'E2E Capability Content',
            'body' => 'Romanian small-business campaign copy grounded in persisted Knowledge and Agent execution results.',
        ],
        $report['capabilities'],
    );
    expect($content['status'])->toBe('draft');

    $reviewed = capabilityE2E(
        $actor,
        $enterprise,
        'marketing.content.review',
        ['content_item_id' => $content['id']],
        $report['capabilities'],
        'e2e-capability-content-review',
    );
    expect($reviewed['status'])->toBe('in_review');

    $approval = capabilityE2E(
        $actor,
        $enterprise,
        'approval.request',
        [
            'agent_assignment_id' => $marketingAssignmentId,
            'agent_execution_id' => $parentExecutionId,
            'capability' => 'marketing.content.publication-ready',
            'target_context' => ['content_item_id' => $content['id']],
        ],
        $report['capabilities'],
        'e2e-capability-publication-approval',
    );
    expect($approval['status'])->toBe(ApprovalRequest::STATUS_PENDING);

    $publicationBlocked = capabilityE2EFailure(
        $actor,
        $enterprise,
        'marketing.content.publication-ready',
        [
            'content_item_id' => $content['id'],
            'approval_request_id' => $approval['id'],
            'agent_assignment_id' => $marketingAssignmentId,
            'agent_execution_id' => $parentExecutionId,
        ],
        $report['capabilities'],
    );
    expect($publicationBlocked->getMessage())->toContain('approved');

    $foreign = capabilityE2E(
        $actor,
        null,
        'enterprise.create',
        [
            'organization_id' => $organization->getKey(),
            'name' => 'E2E Capability Foreign Enterprise',
            'slug' => 'e2e-capability-foreign',
        ],
        $report['capabilities'],
    );
    $foreignEnterprise = Enterprise::query()->findOrFail($foreign['id']);

    $foreignKnowledge = capabilityE2E(
        $actor,
        $foreignEnterprise,
        'knowledge.item.create',
        [
            'title' => 'Foreign Knowledge',
            'content' => 'This record belongs to another Enterprise.',
        ],
        $report['capabilities'],
    );

    $crossEnterprise = capabilityE2EFailure(
        $actor,
        $enterprise,
        'knowledge.index.create',
        ['knowledge_item_id' => $foreignKnowledge['id']],
        $report['capabilities'],
    );
    expect($crossEnterprise)->toBeInstanceOf(Throwable::class);

    $invalidTransition = capabilityE2EFailure(
        $actor,
        $enterprise,
        'agent.assignment.transition',
        [
            'agent_assignment_id' => $marketingAssignmentId,
            'status' => AgentAssignment::STATUS_DRAFT,
        ],
        $report['capabilities'],
    );
    expect($invalidTransition)->toBeInstanceOf(Throwable::class);

    $duplicateAssignmentRetry = capabilityE2E(
        $actor,
        $enterprise,
        'agent.assignment.create',
        [
            'agent_descriptor_id' => $marketing->getKey(),
            'objective' => 'A repeated request must return the existing Assignment.',
        ],
        $report['capabilities'],
        'e2e-capability-assignment-marketing',
        'e2e-capability-assignment-marketing',
    );
    expect($duplicateAssignmentRetry['id'])->toBe($marketingAssignmentId);

    $persistedGraph = [
        'enterprise' => Enterprise::query()->whereKey($enterprise->getKey())->firstOrFail()->toArray(),
        'enterprise_context' => EnterpriseContext::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail()->toArray(),
        'knowledge_items' => KnowledgeItem::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'knowledge_indexes' => KnowledgeIndexRecord::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'knowledge_units' => KnowledgeIndexUnit::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'memory_episodic' => AgentEpisodicMemory::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'memory_semantic' => AgentSemanticMemory::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'agents' => AgentDescriptor::query()->whereIn('id', [$marketing->getKey(), $product->getKey()])->get()->toArray(),
        'assignments' => AgentAssignment::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'executions' => AgentExecution::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'delegations' => AgentDelegation::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'strategies' => Strategy::query()->whereHas('objective', fn ($query) => $query->where('enterprise_id', $enterprise->getKey()))->get()->toArray(),
        'marketing_strategies' => MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'work_items' => WorkItem::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'content_items' => ContentItem::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
        'approvals' => ApprovalRequest::query()->where('enterprise_id', $enterprise->getKey())->get()->toArray(),
    ];

    $report['summary'] = [
        'enterprise_id' => $enterprise->getKey(),
        'capability_count' => count($report['capabilities']),
        'successful_capabilities' => count(array_filter($report['capabilities'], fn (array $entry): bool => ($entry['status'] ?? null) === 'executed')),
        'negative_cases' => count(array_filter($report['capabilities'], fn (array $entry): bool => ($entry['status'] ?? null) === 'failed')),
        'persisted_record_counts' => array_map('count', $persistedGraph),
        'publication_blocked_until_human_approval' => true,
    ];
    $report['persisted_graph'] = $persistedGraph;

    $path = storage_path('app/e2e/E2E-CAPABILITY-20260928.json');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    fwrite(STDOUT, PHP_EOL.json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

    expect($persistedGraph['assignments'])->not->toBeEmpty()
        ->and($persistedGraph['executions'])->not->toBeEmpty()
        ->and($persistedGraph['delegations'])->not->toBeEmpty()
        ->and($persistedGraph['content_items'])->not->toBeEmpty()
        ->and($persistedGraph['approvals'])->not->toBeEmpty();
});