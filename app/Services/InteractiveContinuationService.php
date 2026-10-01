<?php

namespace App\Services;

use App\Data\InteractiveContinuation;
use App\Data\InteractiveReasoningResult;
use App\Enums\AgentExecutionMode;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentExecutionStep;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class InteractiveContinuationService
{
    /** @return array<string, mixed> */
    public function continuation(User $actor, Enterprise $enterprise, AgentExecution $execution): array
    {
        $execution->loadMissing(['agentAssignment.agentDescriptor', 'workflow']);
        $this->assertScope($actor, $enterprise, $execution);

        return InteractiveContinuation::fromExecution($execution, [
            'expert_slugs' => $execution->expert_slugs ?? [],
            'workflow_id' => $execution->workflow_id,
            'workflow_version' => $execution->workflow_version,
        ])->toArray();
    }

    /** @return array<string, mixed> */
    public function continue(User $actor, Enterprise $enterprise, InteractiveReasoningResult $result): array
    {
        $execution = AgentExecution::query()->with(['agentAssignment.agentDescriptor', 'agentAssignment.enterprise'])->findOrFail($result->executionId);
        $this->assertScope($actor, $enterprise, $execution);
        $execution->assertMode(AgentExecutionMode::INTERACTIVE);

        if (isset($execution->execution_context['continuation_results'][$result->idempotencyKey])) {
            return $this->continuation($actor, $enterprise, $execution->refresh());
        }

        $expectedStep = $execution->current_step + 1;
        if ($result->expectedStep !== $expectedStep) {
            throw new AuthorizationException(sprintf('Stale interactive continuation. Expected step [%d], received [%d].', $expectedStep, $result->expectedStep));
        }

        $context = is_array($execution->execution_context) ? $execution->execution_context : [];
        $pending = $context['pending_continuation'] ?? null;
        if (is_array($pending) && isset($pending['expected_step']) && (int) $pending['expected_step'] !== $result->expectedStep) {
            throw new AuthorizationException('Interactive continuation no longer matches the persisted pending step.');
        }

        $execution->current_step = $result->expectedStep;
        $execution->beginReasoning()->save();

        $run = ['results' => []];
        if ($result->capabilityRequests !== []) {
            $runner = app(InteractiveCapabilityStepRunner::class);
            $run = $runner->run($execution, $actor, $execution->agentAssignment, $enterprise, (string) $execution->correlation_id, $result->capabilityRequests, false);
        } else {
            $step = AgentExecutionStep::query()->firstOrCreate(
                ['agent_execution_id' => $execution->getKey(), 'sequence' => $result->expectedStep],
                [
                    'organization_id' => $execution->organization_id,
                    'enterprise_id' => $execution->enterprise_id,
                    'status' => AgentExecutionStep::STATUS_PENDING,
                    'type' => AgentExecutionStep::TYPE_REASONING,
                    'intent' => $execution->prompt,
                    'input_context' => ['mode' => $execution->mode->value, 'step' => $result->expectedStep],
                    'correlation_id' => $execution->correlation_id,
                    'idempotency_key' => $execution->idempotency_key.':reasoning:'.$result->expectedStep,
                ],
            );
            if ($step->status !== AgentExecutionStep::STATUS_COMPLETED) {
                $step->start()->save();
            }
        }
        $step = AgentExecutionStep::query()->where('agent_execution_id', $execution->getKey())->where('sequence', $result->expectedStep)->first();
        $execution->last_result = ['reasoning' => $result->reasoning, 'capability_results' => $run['results'], 'reasoning_step' => $result->expectedStep];

        if ($step !== null) {
            $step->input_context = array_merge((array) $step->input_context, ['reasoning' => $result->reasoning]);
            $step->output = array_merge((array) $step->output, ['reasoning' => $result->reasoning, 'termination' => $result->termination, 'termination_reason' => $result->terminationReason]);
            if ($step->status === AgentExecutionStep::STATUS_RUNNING && $result->termination !== 'continue') {
                $step->complete()->save();
            } else {
                $step->save();
            }
        }

        $this->applyTermination($execution, $result);

        $context = is_array($execution->execution_context) ? $execution->execution_context : [];
        $context['continuation_results'][$result->idempotencyKey] = true;
        unset($context['pending_continuation']);
        $execution->execution_context = $context;
        $execution->save();

        return $this->continuation($actor, $enterprise, $execution->refresh());
    }

    private function applyTermination(AgentExecution $execution, InteractiveReasoningResult $result): void
    {
        $reason = $result->terminationReason;
        match ($result->termination) {
            'continue' => $execution->beginReasoning()->save(),
            'waiting_for_input' => $execution->waitForInput($reason ?? 'Interactive execution requires human input.')->save(),
            'waiting_for_approval' => $execution->waitForApproval($reason ?? 'Interactive execution requires approval.')->save(),
            'delegated' => $execution->markDelegated($reason ?? 'Interactive execution delegated.')->save(),
            'paused' => $execution->pause($reason ?? 'Interactive execution paused.')->save(),
            'completed' => $execution->complete($reason ?? 'interactive_continuation_completed')->save(),
            default => null,
        };
    }

    private function assertScope(User $actor, Enterprise $enterprise, AgentExecution $execution): void
    {
        if ((int) $execution->enterprise_id !== (int) $enterprise->getKey() || (int) $execution->organization_id !== (int) $enterprise->organization_id) {
            throw new AuthorizationException('Interactive execution does not belong to the requested Enterprise.');
        }
        Gate::forUser($actor)->authorize('view', $execution);
        if ($execution->actor_id !== $actor->getKey()) {
            throw new AuthorizationException('Only the execution actor may continue this Agent execution.');
        }
        if (! $execution->agentAssignment instanceof AgentAssignment) {
            throw new AuthorizationException('Interactive execution requires its Agent assignment.');
        }
    }
}