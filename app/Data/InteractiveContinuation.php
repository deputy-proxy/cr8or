<?php

namespace App\Data;

use App\Enums\AgentExecutionMode;
use App\Models\AgentExecution;
use InvalidArgumentException;

final readonly class InteractiveContinuation
{
    /**
     * @param  array<string, mixed>  $agentContext
     * @param  array<string, mixed>  $authoritativeResults
     * @param  array<string, mixed>  $reasoningSchema
     * @param  array<string, mixed>  $humanGate
     */
    public function __construct(
        public int $executionId,
        public AgentExecutionMode $mode,
        public string $status,
        public int $currentStep,
        public int $expectedStep,
        public ?int $workflowStageId,
        public array $agentContext,
        public array $authoritativeResults,
        public array $reasoningSchema,
        public string $tool,
        public string $correlationId,
        public string $idempotencyKey,
        public array $humanGate = [],
    ) {
        if ($this->mode !== AgentExecutionMode::INTERACTIVE) {
            throw new InvalidArgumentException('Interactive continuations require interactive executions.');
        }
    }

    /** @param array<string, mixed> $agentContext */
    public static function fromExecution(AgentExecution $execution, array $agentContext = []): self
    {
        $context = is_array($execution->execution_context) ? $execution->execution_context : [];
        $expectedStep = max(1, $execution->current_step + 1);
        $stageId = isset($context['workflow_stage_id']) ? (int) $context['workflow_stage_id'] : null;

        $status = $execution->status;
        $terminal = in_array($status, [AgentExecution::STATUS_COMPLETED, AgentExecution::STATUS_FAILED, AgentExecution::STATUS_CANCELLED], true);

        return new self(
            executionId: (int) $execution->getKey(),
            mode: $execution->mode,
            status: $status,
            currentStep: (int) $execution->current_step,
            expectedStep: $expectedStep,
            workflowStageId: $stageId,
            agentContext: $agentContext + [
                'agent_slug' => $execution->agent_slug,
                'enterprise_id' => $execution->enterprise_id,
                'assignment_id' => $execution->agent_assignment_id,
            ],
            authoritativeResults: is_array($execution->last_result) ? $execution->last_result : [],
            reasoningSchema: $terminal ? [] : [
                'expected_step' => 'integer',
                'reasoning' => 'string',
                'capability_requests' => 'array',
                'delegation_requests' => 'array',
                'termination' => ['continue', 'waiting_for_input', 'waiting_for_approval', 'delegated', 'paused', 'completed'],
                'termination_reason' => 'string|null',
            ],
            tool: $terminal ? '' : 'mcp_agent_continue',
            correlationId: (string) $execution->correlation_id,
            idempotencyKey: (string) $execution->idempotency_key,
            humanGate: match ($status) {
                AgentExecution::STATUS_WAITING_FOR_INPUT => ['type' => 'input', 'reason' => $execution->state_reason],
                AgentExecution::STATUS_WAITING_FOR_APPROVAL => ['type' => 'approval', 'reason' => $execution->state_reason],
                default => [],
            },
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'mode' => $this->mode->value,
            'status' => $this->status,
            'current_step' => $this->currentStep,
            'expected_step' => $this->expectedStep,
            'workflow_stage_id' => $this->workflowStageId,
            'agent_context' => $this->agentContext,
            'authoritative_results' => $this->authoritativeResults,
            'reasoning_result_schema' => $this->reasoningSchema,
            'continuation_tool' => $this->tool,
            'correlation_id' => $this->correlationId,
            'idempotency_key' => $this->idempotencyKey,
            'human_gate' => $this->humanGate,
        ];
    }
}