<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\InteractiveReasoningResult;
use App\Enums\AgentExecutionMode;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\AgentExecutionResourceService;
use App\Services\AgentExecutionService;
use App\Services\InteractiveContinuationService;

final class ResumeAgentExecution implements Operation
{
    public function __construct(
        private readonly AgentExecutionService $executions,
        private readonly AgentExecutionResourceService $resources,
        private readonly InteractiveContinuationService $continuations,
    ) {}

    public function execute(User $actor, array $input): mixed
    {
        $execution = $input['execution'] ?? AgentExecution::query()->findOrFail((int) $input['agent_execution_id']);
        $enterprise = $input['enterprise'] ?? Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        if ($execution->mode === AgentExecutionMode::INTERACTIVE && array_key_exists('capability_requests', $input)) {
            $expectedStep = isset($input['expected_step'])
                ? (int) $input['expected_step']
                : $execution->current_step + 1;
            $idempotencyKey = isset($input['idempotency_key']) && is_string($input['idempotency_key'])
                ? trim($input['idempotency_key'])
                : $execution->idempotency_key.':continuation:'.$expectedStep;

            return $this->continuations->continue(
                $actor,
                $enterprise,
                InteractiveReasoningResult::from([
                    'agent_execution_id' => $execution->getKey(),
                    'expected_step' => $expectedStep,
                    'idempotency_key' => $idempotencyKey,
                    'reasoning' => (string) ($input['reasoning'] ?? ''),
                    'capability_requests' => array_values($input['capability_requests'] ?? []),
                    'delegation_requests' => array_values($input['delegation_requests'] ?? []),
                    'termination' => (string) ($input['termination'] ?? 'continue'),
                    'termination_reason' => isset($input['termination_reason']) ? (string) $input['termination_reason'] : null,
                ]),
            );
        }

        $this->executions->queueResume($execution, $actor);

        return $this->resources->get($actor, $enterprise, $execution->refresh());
    }
}