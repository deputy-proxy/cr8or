<?php

use App\AI\Data\ModelResult;
use App\AI\Providers\FakeModelProvider;
use App\Data\AgentExecutionRequest;
use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;
use App\Data\KnowledgeRetrievalResultItem;
use App\Models\AgentAssignment;
use App\Models\AgentDescriptor;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\User;
use App\Services\AgentCapabilityAuthorizer;
use App\Services\AgentExecutionService;
use App\Services\KnowledgeRetrievalService;
use App\Services\McpContextAssembler;

beforeEach(function (): void {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

function knowledgeRuntimeFixture(): array
{
    $actor = User::factory()->create();
    $enterprise = Enterprise::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $enterprise->organization_id,
    ]);

    $descriptor = AgentDescriptor::query()->where('slug', 'marketing')->firstOrFail();
    $assignment = AgentAssignment::factory()
        ->forEnterprise($enterprise)
        ->create(['agent_descriptor_id' => $descriptor->getKey()]);

    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'title' => 'Approval policy',
        'summary' => 'Approval requires a manager.',
    ]);

    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'content' => 'Approval requires a manager.',
        'version' => 3,
    ]);

    return compact('actor', 'enterprise', 'assignment', 'item');
}

it('automatically retrieves governed Knowledge for Agents that require Knowledge', function (): void {
    $fixture = knowledgeRuntimeFixture();
    extract($fixture);

    app()->instance(KnowledgeRetrievalService::class, new KnowledgeRetrievalService(
        new class($item) implements \App\Contracts\KnowledgeRetrievalProvider
        {
            public function __construct(private KnowledgeItem $item) {}

            public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
            {
                return new KnowledgeRetrievalResult(
                    status: 'succeeded',
                    correlationId: $request->correlationId ?? 'knowledge-runtime',
                    items: [
                        new KnowledgeRetrievalResultItem(
                            knowledgeItemId: $this->item->getKey(),
                            title: $this->item->title,
                            summary: $this->item->summary,
                            relevance: 0.97,
                        ),
                    ],
                    candidateCount: 1,
                    metadata: ['mode' => 'hybrid'],
                );
            }
        },
    ));

    $provider = new FakeModelProvider(function ($request): ModelResult {
        $retrieved = $request->context['retrieved_knowledge'];

        expect($retrieved['items'])->toHaveCount(1)
            ->and($retrieved['items'][0]['title'])->toBe('Approval policy')
            ->and($retrieved['items'][0]['version'])->toMatchArray([
                'version' => 3,
            ])
            ->and($retrieved['items'][0]['metadata']['provenance_current'])->toBeTrue()
            ->and($retrieved['sufficiency']['status'])->toBe('sufficient')
            ->and($retrieved['retrieval']['metadata']['mode'])->toBe('hybrid');

        return new ModelResult(
            text: 'Knowledge-informed answer.',
            structured: [
                'answer' => 'Knowledge-informed answer.',
                'decision_title' => 'Knowledge decision',
                'decision_summary' => 'The retrieved policy is relevant.',
                'decision_rationale' => 'The current Knowledge version was retrieved inside the Enterprise boundary.',
                'evidence_references' => ['knowledge:approval-policy'],
                'selected_experts' => [],
                'capability_requests' => [],
                'delegation_requests' => [],
                'termination' => 'completed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'knowledge-runtime',
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'What does the approval policy require?',
        correlationId: 'knowledge-runtime',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(\App\Models\AgentExecution::STATUS_COMPLETED);
});

it('degrades Knowledge retrieval failures into an explicit insufficiency signal', function (): void {
    $fixture = knowledgeRuntimeFixture();
    extract($fixture);

    app()->instance(KnowledgeRetrievalService::class, new KnowledgeRetrievalService(
        new class implements \App\Contracts\KnowledgeRetrievalProvider
        {
            public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult
            {
                throw new RuntimeException('retrieval provider unavailable');
            }
        },
    ));

    $provider = new FakeModelProvider(function ($request): ModelResult {
        expect($request->context['retrieved_knowledge']['items'])->toBe([])
            ->and($request->context['retrieved_knowledge']['sufficiency'])->toMatchArray([
                'status' => 'unavailable',
                'reason' => 'retrieval_failed',
            ]);

        return new ModelResult(
            text: 'Insufficient Knowledge, using authorized context only.',
            structured: [
                'answer' => 'Insufficient Knowledge, using authorized context only.',
                'decision_title' => 'Knowledge insufficiency',
                'decision_summary' => 'Retrieval was unavailable.',
                'decision_rationale' => 'The execution continued without fabricated Knowledge.',
                'capability_requests' => [],
                'termination' => 'completed',
            ],
            provider: 'fake',
            model: 'test',
            invocationId: 'knowledge-runtime-failure',
        );
    });

    $result = (new AgentExecutionService(
        $provider,
        app(McpContextAssembler::class),
        app(AgentCapabilityAuthorizer::class),
    ))->execute(new AgentExecutionRequest(
        actor: $actor,
        assignment: $assignment,
        prompt: 'Answer using available Knowledge.',
        correlationId: 'knowledge-runtime-failure',
    ));

    expect($result->succeeded())->toBeTrue()
        ->and($result->execution->status)->toBe(\App\Models\AgentExecution::STATUS_COMPLETED);
});