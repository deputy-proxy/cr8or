<?php

namespace App\Services;

use App\Data\CapabilityRequest;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\AgentExecutionStep;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class InteractiveCapabilityStepRunner
{
    public function __construct(private readonly CapabilityExecutionService $capabilities) {}

    /**
     * Execute the persisted interactive Capability plan as durable steps.
     *
     * Contract:
     * - capability_requests is a flat plan.
     * - each request may specify a positive integer "step"; omitted means step 1.
     * - requests with the same step execute in one durable AgentExecutionStep.
     * - steps are executed in ascending order and never through ModelProvider.
     * - a waiting Capability leaves the current step and execution resumable.
     * - an empty plan means the execution is waiting for interactive input, not completed.
     *
     * @param  list<array<string, mixed>>  $payload
     * @return array{requests: list<CapabilityRequest>, results: list<array<string, mixed>>}
     */
    public function run(
        AgentExecution $execution,
        User $actor,
        AgentAssignment $assignment,
        Enterprise $enterprise,
        string $correlationId,
        array $payload,
    ): array {
        $plan = $this->normalizePlan($payload);

        if ($plan === []) {
            $reason = 'Interactive execution is waiting for a Capability plan.';
            $execution->waitForInput($reason)->save();

            return ['requests' => [], 'results' => []];
        }

        $stepNumbers = array_keys($plan);
        $maxSteps = max(1, $execution->max_steps);

        if (count($stepNumbers) > $maxSteps) {
            throw new AuthorizationException(sprintf(
                'Interactive Capability plan contains [%d] steps, exceeding the execution maximum of [%d].',
                count($stepNumbers),
                $maxSteps,
            ));
        }

        $allRequests = [];
        $allResults = [];

        foreach ($plan as $sequence => $entries) {
            $step = AgentExecutionStep::query()->firstOrCreate(
                ['agent_execution_id' => $execution->getKey(), 'sequence' => $sequence],
                [
                    'organization_id' => $execution->organization_id,
                    'enterprise_id' => $execution->enterprise_id,
                    'status' => AgentExecutionStep::STATUS_PENDING,
                    'type' => AgentExecutionStep::TYPE_CAPABILITY,
                    'intent' => $execution->prompt,
                    'input_context' => [
                        'mode' => $execution->mode->value,
                        'step' => $sequence,
                        'plan' => $entries,
                    ],
                    'correlation_id' => $correlationId,
                    'idempotency_key' => $execution->idempotency_key.':interactive:'.$sequence,
                ],
            );

            if ($step->status === AgentExecutionStep::STATUS_COMPLETED) {
                $execution->current_step = max($execution->current_step, $sequence);
                $execution->save();

                continue;
            }

            if ($step->status === AgentExecutionStep::STATUS_FAILED) {
                throw new AuthorizationException('Interactive execution cannot continue from a failed Capability step.');
            }

            $requests = $this->requestsForStep(
                $entries,
                $execution,
                $actor,
                $assignment,
                $correlationId,
            );

            $step->intent = $execution->prompt;
            $step->input_context = [
                'mode' => $execution->mode->value,
                'step' => $sequence,
                'plan' => $entries,
            ];
            $step->capability_requests = array_map(
                static fn (CapabilityRequest $request): array => $request->toArray(),
                $requests,
            );
            $step->failure_reason = null;
            $step->failure_code = null;
            $step->start()->save();

            $execution->current_step = $sequence;
            $execution->transitionTo(AgentExecution::STATUS_EXECUTING)->save();

            $results = $this->executeRequests($requests, $step);

            $step->output = ['capability_results' => $results];
            $allRequests = [...$allRequests, ...$requests];
            $allResults = [...$allResults, ...$results];

            $waiting = collect($results)->first(
                static fn (array $result): bool => ($result['status'] ?? null) === 'waiting',
            );

            if (is_array($waiting)) {
                $reason = 'Approval required for capability ['.($waiting['capability'] ?? 'unknown').'].';
                $step->wait($reason)->save();
                $execution->last_result = [
                    'capability_results' => $allResults,
                    'mode' => $execution->mode->value,
                    'waiting_step' => $sequence,
                ];
                $execution->waitForApproval($reason)->save();

                return ['requests' => $allRequests, 'results' => $allResults];
            }

            $step->complete()->save();
            $execution->current_step = max($execution->current_step, $sequence);
            $execution->last_result = [
                'capability_results' => $allResults,
                'mode' => $execution->mode->value,
                'completed_step' => $sequence,
            ];

            if ($sequence < max($stepNumbers)) {
                $execution->beginReasoning()->save();
            } else {
                $execution->complete('interactive_capability_plan_completed')->save();
            }
        }

        return ['requests' => $allRequests, 'results' => $allResults];
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @return array<int, list<array<string, mixed>>>
     */
    private function normalizePlan(array $payload): array
    {
        $plan = [];

        foreach ($payload as $index => $request) {

            $capability = isset($request['capability']) && is_string($request['capability'])
                ? trim($request['capability'])
                : '';

            if ($capability === '') {
                throw new AuthorizationException('Interactive Capability requests require a capability identifier.');
            }

            $step = $request['step'] ?? 1;
            if (filter_var($step, FILTER_VALIDATE_INT) === false || (int) $step < 1) {
                throw new AuthorizationException('Interactive Capability request steps must be positive integers.');
            }

            $plan[(int) $step][] = $request;
        }

        ksort($plan);

        $expected = 1;
        foreach (array_keys($plan) as $step) {
            if ($step !== $expected) {
                throw new AuthorizationException('Interactive Capability plan steps must be contiguous starting at 1.');
            }

            $expected++;
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<CapabilityRequest>
     */
    private function requestsForStep(
        array $entries,
        AgentExecution $execution,
        User $actor,
        AgentAssignment $assignment,
        string $correlationId,
    ): array {
        return array_map(
            function (array $request, int $index) use ($execution, $actor, $assignment, $correlationId): CapabilityRequest {
                $approval = isset($request['approval_request_id'])
                    ? ApprovalRequest::query()->findOrFail((int) $request['approval_request_id'])
                    : null;

                $idempotencyKey = isset($request['idempotency_key']) && is_string($request['idempotency_key'])
                    ? trim($request['idempotency_key'])
                    : hash('sha256', implode('|', [
                        $execution->idempotency_key,
                        (string) ($request['step'] ?? 1),
                        (string) $index,
                        (string) $request['capability'],
                    ]));

                return new CapabilityRequest(
                    capability: trim((string) $request['capability']),
                    assignment: $assignment,
                    execution: $execution,
                    actor: $actor,
                    targetContext: isset($request['target_context']) && is_array($request['target_context']) ? $request['target_context'] : [],
                    inputPayload: isset($request['input_payload']) && is_array($request['input_payload']) ? $request['input_payload'] : [],
                    approval: $approval,
                    correlationId: $correlationId,
                    idempotencyKey: $idempotencyKey,
                );
            },
            $entries,
            array_keys($entries),
        );
    }

    /**
     * @param  list<CapabilityRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function executeRequests(array $requests, AgentExecutionStep $step): array
    {
        $existing = (array) ($step->output['capability_results'] ?? []);
        $results = [];

        foreach ($requests as $request) {
            $existingResult = collect($existing)->first(
                static fn (mixed $result): bool => is_array($result)
                    && ($result['idempotency_key'] ?? null) === $request->idempotencyKey,
            );

            if (is_array($existingResult)) {
                $waiting = ($existingResult['status'] ?? null) === 'waiting';
                $hasApproval = $request->approval !== null;

                if (! $waiting || ! $hasApproval) {
                    $results[] = $existingResult;

                    continue;
                }
            }

            $result = $this->capabilities->execute($request);
            $result['idempotency_key'] = $request->idempotencyKey;
            $results[] = $result;
        }

        return $results;
    }
}