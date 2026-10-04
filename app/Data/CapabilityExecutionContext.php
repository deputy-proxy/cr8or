<?php

namespace App\Data;

use App\Enums\CapabilityExecutionMode;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use InvalidArgumentException;

final readonly class CapabilityExecutionContext
{
    public function __construct(
        public CapabilityExecutionMode $mode,
        public ?AgentAssignment $assignment = null,
        public ?AgentExecution $execution = null,
        public ?ApprovalRequest $approval = null,
        public ?WorkflowExecution $workflowExecution = null,
        public ?WorkflowStage $workflowStage = null,
    ) {
        if ($this->mode === CapabilityExecutionMode::AGENT
            && ($this->assignment === null || $this->execution === null)
        ) {
            throw new InvalidArgumentException('Agent Capability execution context requires assignment and execution.');
        }

        if ($this->mode === CapabilityExecutionMode::WORKFLOW
            && ($this->workflowExecution === null || $this->workflowStage === null)
        ) {
            throw new InvalidArgumentException('Workflow Capability execution context requires Workflow execution and stage.');
        }

        if ($this->mode !== CapabilityExecutionMode::AGENT
            && ($this->assignment !== null || $this->execution !== null)
        ) {
            throw new InvalidArgumentException('Only Agent Capability execution context may contain Agent provenance.');
        }

        if ($this->mode !== CapabilityExecutionMode::WORKFLOW
            && ($this->workflowExecution !== null || $this->workflowStage !== null)
        ) {
            throw new InvalidArgumentException('Only Workflow Capability execution context may contain Workflow provenance.');
        }

        if ($this->workflowExecution !== null
            && $this->workflowStage !== null
            && $this->workflowStage->workflow_id !== $this->workflowExecution->workflow_id
        ) {
            throw new InvalidArgumentException('Workflow stage must belong to its Workflow execution.');
        }
    }

    public function isAgentBacked(): bool
    {
        return $this->mode === CapabilityExecutionMode::AGENT;
    }

    public function isWorkflowBacked(): bool
    {
        return $this->mode === CapabilityExecutionMode::WORKFLOW;
    }

    public function workflowExecutionId(): ?int
    {
        return $this->workflowExecution?->getKey();
    }
}