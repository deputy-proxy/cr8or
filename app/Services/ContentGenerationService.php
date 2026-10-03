<?php

namespace App\Services;

use App\Data\AgentExecutionRequest;
use App\Data\CapabilityInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\User;

final class ContentGenerationService
{
    public function __construct(
        private readonly AgentExecutionService $executions,
        private readonly CapabilityInvocationService $capabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $modelOptions
     */
    public function generate(
        User $actor,
        AgentAssignment $assignment,
        Enterprise $enterprise,
        string $prompt,
        array $attributes,
        array $modelOptions = [],
    ): ContentItem {
        $result = $this->executions->execute(new AgentExecutionRequest(
            actor: $actor,
            assignment: $assignment,
            prompt: $prompt,
            targetContext: ['enterprise_id' => $enterprise->getKey()],
            options: $modelOptions,
        ));

        $invocation = $this->capabilities->invoke(new CapabilityInvocationRequest(
            capability: 'marketing.content.create',
            actor: $actor,
            enterprise: $enterprise,
            assignment: $assignment,
            execution: $result->execution,
            expertSlug: 'copywriting',
            targetContext: ['enterprise_id' => $enterprise->getKey()],
            inputPayload: [
                ...$attributes,
                'body' => $result->modelResult->text,
                'agent_execution_id' => $result->execution->getKey(),
                'agent_decision_id' => $result->decision?->getKey(),
            ],
            correlationId: $result->execution->correlation_id,
            idempotencyKey: 'content-generation-create-'.$result->execution->getKey(),
        ));

        $rawResult = $invocation['raw_result'] ?? null;
        if ($invocation['status'] !== 'executed' || ! $rawResult instanceof ContentItem) {
            throw new \LogicException('Content creation Capability invocation did not execute.');
        }

        return $rawResult;
    }

    /**
     * @param  array<string, mixed>  $modelOptions
     */
    public function revise(
        User $actor,
        AgentAssignment $assignment,
        ContentItem $item,
        string $prompt,
        array $modelOptions = [],
    ): ContentItem {
        $result = $this->executions->execute(new AgentExecutionRequest(
            actor: $actor,
            assignment: $assignment,
            prompt: $prompt,
            targetContext: ['content_item_id' => $item->getKey()],
            options: $modelOptions,
        ));

        $invocation = $this->capabilities->invoke(new CapabilityInvocationRequest(
            capability: 'marketing.content.update',
            actor: $actor,
            enterprise: $item->enterprise,
            assignment: $assignment,
            execution: $result->execution,
            expertSlug: 'copywriting',
            targetContext: ['content_item_id' => $item->getKey()],
            inputPayload: [
                'content_item_id' => $item->getKey(),
                'body' => $result->modelResult->text,
                'agent_execution_id' => $result->execution->getKey(),
                'agent_decision_id' => $result->decision?->getKey(),
            ],
            correlationId: $result->execution->correlation_id,
            idempotencyKey: 'content-generation-revise-'.$result->execution->getKey(),
        ));

        $rawResult = $invocation['raw_result'] ?? null;
        if ($invocation['status'] !== 'executed' || ! $rawResult instanceof ContentItem) {
            throw new \LogicException('Content revision Capability invocation did not execute.');
        }

        return $rawResult;
    }
}