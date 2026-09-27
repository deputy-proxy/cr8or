<?php

namespace App\Services;

use App\Agents\Agent;
use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ModelProvider;
use App\AI\Data\AgentExecutionResult;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use App\AI\ReasoningOutputValidator;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentExecutionRequest;
use App\Data\CapabilityRequest;
use App\Data\ExpertInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\AgentExecutionStep;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class AgentExecutionService
{
    public function __construct(
        private readonly ModelProvider $provider,
        private readonly McpContextAssembler $contextAssembler,
        private readonly AgentCapabilityAuthorizer $capabilityAuthorizer,
        private readonly ?ExecutionCorrelationService $correlation = null,
        private readonly ?CapabilityRegistry $capabilities = null,
        private readonly ?ExpertInvocationService $expertInvocations = null,
        private readonly ?AgentMemoryRuntimeService $memoryRuntime = null,
        private readonly ?CapabilityExecutionService $capabilityExecution = null,
    ) {}

    public function execute(AgentExecutionRequest $request): AgentExecutionResult
    {
        $actor = $request->actor;
        $assignment = $request->assignment;
        $assignment->loadMissing(['agentDescriptor', 'organization', 'enterprise']);

        Gate::forUser($actor)->authorize('view', $assignment);

        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException('The Agent assignment is disabled.');
        }

        $enterprise = $assignment->enterprise;
        if (! $enterprise instanceof Enterprise || $enterprise->organization_id !== $assignment->organization_id) {
            throw new AuthorizationException('Agent execution requires an enterprise-scoped assignment.');
        }

        $descriptor = $assignment->agentDescriptor;
        $agent = app($descriptor->resolveRuntimeClass());
        if (! $agent instanceof Agent) {
            throw new AuthorizationException('The configured Agent runtime is invalid.');
        }

        $expertSlugs = $request->expertRoutingKey !== null
            ? $agent->expertsFor($request->expertRoutingKey)
            : $request->expertSlugs;

        $correlation = $this->correlation ?? app(ExecutionCorrelationService::class);
        $correlationId = $correlation->resolve($request->correlationId);
        $idempotencyKey = $request->idempotencyKey ?? hash('sha256', implode('|', [$assignment->getKey(), $correlationId, $request->prompt]));

        $existing = AgentExecution::query()
            ->where('organization_id', $assignment->organization_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, [AgentExecution::STATUS_COMPLETED, AgentExecution::STATUS_FAILED, AgentExecution::STATUS_CANCELLED], true)) {
                return $this->resultFromExecution($existing);
            }

            return $this->run(
                $existing,
                $actor,
                $assignment,
                $agent,
                $enterprise,
                is_array($existing->expert_slugs) ? $existing->expert_slugs : $expertSlugs,
                (string) $existing->prompt,
                is_array($existing->target_context) ? $existing->target_context : [],
                is_array($existing->model_options) ? $existing->model_options : [],
                $request->delegation,
                (string) ($existing->correlation_id ?? $correlationId),
                is_array($existing->execution_context) ? $existing->execution_context : [],
            );
        }

        $executionTargetContext = $this->executionTargetContext($agent, $request->targetContext, $request->prompt);

        $context = $this->contextAssembler->forAgent(
            $actor,
            $enterprise,
            $this->requiredContext($agent),
            $executionTargetContext,
            $assignment,
        );

        $execution = AgentExecution::query()->create([
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'agent_descriptor_id' => $descriptor->getKey(),
            'agent_assignment_id' => $assignment->getKey(),
            'actor_id' => $actor->getKey(),
            'organization_name' => $assignment->organization->name,
            'enterprise_name' => $enterprise->name,
            'agent_slug' => $descriptor->slug,
            'agent_runtime_class' => $descriptor->runtime_class,
            'agent_definition_version' => $agent->definitionVersion(),
            'actor_name' => $actor->name,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'status' => AgentExecution::STATUS_REQUESTED,
            'requested_at' => now(),
            'max_steps' => $this->maxSteps($request->options),
            'current_step' => 0,
            'prompt' => $request->prompt,
            'target_context' => $executionTargetContext,
            'expert_slugs' => $expertSlugs,
            'model_options' => $request->options,
            'execution_context' => $context->toArray(),
        ]);

        return $this->run(
            $execution,
            $actor,
            $assignment,
            $agent,
            $enterprise,
            $expertSlugs,
            $request->prompt,
            $executionTargetContext,
            $request->options,
            $request->delegation,
            $correlationId,
            $context->toArray(),
        );
    }

    public function resume(AgentExecution $execution, User $actor): AgentExecutionResult
    {
        $execution->loadMissing(['agentAssignment.agentDescriptor', 'agentAssignment.organization', 'agentAssignment.enterprise']);
        $assignment = $execution->agentAssignment;

        if (! $assignment instanceof AgentAssignment) {
            throw new AuthorizationException('Agent execution cannot be resumed without its Agent assignment.');
        }

        Gate::forUser($actor)->authorize('view', $assignment);

        if ($execution->actor_id !== $actor->getKey()) {
            throw new AuthorizationException('Only the execution actor may resume this Agent execution.');
        }

        if (! in_array($execution->status, [
            AgentExecution::STATUS_WAITING_FOR_INPUT,
            AgentExecution::STATUS_WAITING_FOR_APPROVAL,
            AgentExecution::STATUS_DELEGATED,
            AgentExecution::STATUS_PAUSED,
        ], true)) {
            return $this->resultFromExecution($execution);
        }

        $enterprise = $assignment->enterprise;
        $agent = app($assignment->agentDescriptor->resolveRuntimeClass());

        if (! $enterprise instanceof Enterprise || ! $agent instanceof Agent) {
            throw new AuthorizationException('The configured Agent execution runtime is invalid.');
        }

        return $this->run(
            $execution,
            $actor,
            $assignment,
            $agent,
            $enterprise,
            is_array($execution->expert_slugs) ? $execution->expert_slugs : [],
            (string) $execution->prompt,
            is_array($execution->target_context) ? $execution->target_context : [],
            is_array($execution->model_options) ? $execution->model_options : [],
            null,
            (string) $execution->correlation_id,
            is_array($execution->execution_context) ? $execution->execution_context : [],
        );
    }

    /**
     * @param  list<string>  $expertSlugs
     * @param  array<string, mixed>  $targetContext
     * @param  array<string, mixed>  $modelOptions
     * @param  array<string, mixed>  $contextData
     */
    private function run(
        AgentExecution $execution,
        User $actor,
        AgentAssignment $assignment,
        Agent $agent,
        Enterprise $enterprise,
        array $expertSlugs,
        string $prompt,
        array $targetContext,
        array $modelOptions,
        ?AgentDelegation $delegation,
        string $correlationId,
        array $contextData,
    ): AgentExecutionResult {
        $correlation = $this->correlation ?? app(ExecutionCorrelationService::class);
        $memoryRuntime = $this->memoryRuntime ?? app(AgentMemoryRuntimeService::class);
        $lastResult = null;
        $lastDecision = null;
        $authorizedRequests = [];
        $step = null;

        try {
            if ($execution->status === AgentExecution::STATUS_REQUESTED) {
                $execution->start()->save();
            } elseif ($execution->status !== AgentExecution::STATUS_REASONING) {
                $execution->beginReasoning()->save();
            }

            while ($execution->current_step < $execution->max_steps) {
                $sequence = $execution->current_step + 1;

                $step = AgentExecutionStep::query()->firstOrCreate(
                    ['agent_execution_id' => $execution->getKey(), 'sequence' => $sequence],
                    [
                        'organization_id' => $execution->organization_id,
                        'enterprise_id' => $execution->enterprise_id,
                        'status' => AgentExecutionStep::STATUS_PENDING,
                        'type' => AgentExecutionStep::TYPE_REASONING,
                        'correlation_id' => $correlationId,
                        'idempotency_key' => $execution->idempotency_key.':'.$sequence,
                    ],
                );

                if ($step->status === AgentExecutionStep::STATUS_COMPLETED) {
                    $execution->current_step = $sequence;
                    $execution->save();

                    continue;
                }

                $step->intent = $execution->next_step ?? ($sequence === 1 ? $prompt : 'Continue the current task using the prior structured result.');
                $step->input_context = $this->stepContext($execution, $contextData);
                $step->start()->save();
                $execution->current_step = $sequence;
                $execution->beginReasoning()->save();

                $expertResults = $this->coordinateExperts(
                    $agent,
                    $contextData,
                    $expertSlugs,
                    $assignment,
                    $actor,
                    $execution,
                    $step->intent,
                    $targetContext,
                );

                $stepContext = array_merge($contextData, [
                    'execution_id' => $execution->getKey(),
                    'step' => $sequence,
                    'max_steps' => $execution->max_steps,
                    'previous_result' => $execution->last_result,
                    'next_step' => $execution->next_step,
                    'execution_history' => $execution->steps()->orderBy('sequence')->get()->map(
                        fn (AgentExecutionStep $item): array => [
                            'sequence' => $item->sequence,
                            'status' => $item->status,
                            'intent' => $item->intent,
                            'output' => $item->output,
                        ],
                    )->all(),
                    'instructions' => [
                        'agent' => ['name' => $agent->name(), 'instructions' => $agent->instructions()],
                        'experts' => $expertResults['instructions'] ?? [],
                    ],
                    'agent' => [
                        'slug' => $assignment->agentDescriptor->slug,
                        'name' => $agent->name(),
                        'responsibilities' => $agent->responsibilities(),
                        'capabilities' => $agent->capabilities(),
                        'capability_map' => $agent->capabilityMap(),
                        'capability_gaps' => $agent->capabilityGaps(),
                        'decision_boundaries' => $agent->decisionBoundaries(),
                        'expected_outputs' => $agent->expectedOutputs(),
                        'approval_sensitive_capabilities' => $agent->approvalSensitiveCapabilities(),
                        'definition_version' => $agent->definitionVersion(),
                    ],
                    'experts' => $expertResults,
                    'target_context' => $targetContext,
                ]);

                $modelRequest = new ModelRequest(
                    prompt: $step->intent,
                    instructions: implode("\n", [$agent->instructions(), $this->instructions($agent, $expertResults)]),
                    context: $stepContext,
                    provider: isset($modelOptions['provider']) ? (string) $modelOptions['provider'] : null,
                    model: isset($modelOptions['model']) ? (string) $modelOptions['model'] : null,
                    timeout: isset($modelOptions['timeout']) ? (int) $modelOptions['timeout'] : null,
                    structuredOutputSchema: $this->outputSchema($agent),
                    correlationId: $correlationId,
                );

                $execution->transitionTo(AgentExecution::STATUS_EXECUTING)->save();
                $modelResult = $this->provider->generate($modelRequest);
                $structured = $modelResult->structured ?? ['answer' => $modelResult->text];

                $modelResult = new ModelResult(
                    text: $modelResult->text,
                    structured: ReasoningOutputValidator::normalizeAgent($structured),
                    provider: $modelResult->provider,
                    model: $modelResult->model,
                    invocationId: $modelResult->invocationId,
                    usage: $modelResult->usage,
                    correlationId: $modelResult->correlationId,
                );

                $previousCapabilityResults = is_array($execution->last_result['capability_results'] ?? null)
                    ? $execution->last_result['capability_results']
                    : [];

                $execution->provider = $modelResult->provider;
                $execution->external_execution_id = $modelResult->invocationId;
                $execution->last_result = [
                    'text' => $modelResult->text,
                    'structured' => $modelResult->structured,
                    'provider' => $modelResult->provider,
                    'model' => $modelResult->model,
                    'invocation_id' => $modelResult->invocationId,
                    'usage' => $modelResult->usage,
                    'correlation_id' => $modelResult->correlationId,
                ];

                $authorizedRequests = $this->authorizeCapabilityRequests(
                    $actor,
                    $assignment,
                    $enterprise,
                    $execution,
                    $modelResult,
                    $targetContext,
                    $delegation,
                );

                $capabilityResults = $this->executeCapabilityRequests(
                    $authorizedRequests,
                );

                $execution->last_result = array_merge($execution->last_result ?? [], [
                    'capability_results' => array_merge($previousCapabilityResults, $capabilityResults),
                ]);

                $decision = $this->persistDecision($execution, $modelResult);
                $lastDecision = $decision;
                $lastResult = $modelResult;

                $termination = $this->termination($modelResult);
                $execution->next_step = $termination['next_step'];
                $execution->state_reason = $termination['reason'];
                $step->output = $modelResult->structured;
                $step->capability_requests = array_map(
                    fn (CapabilityRequest $request): array => $request->toArray(),
                    $authorizedRequests,
                );

                $approvalWait = collect($capabilityResults)->first(
                    static fn (array $result): bool => ($result['status'] ?? null) === 'waiting',
                );

                if (is_array($approvalWait)) {
                    $reason = 'Approval required for capability ['.($approvalWait['capability'] ?? 'unknown').'].';
                    $step->output = array_merge($step->output ?? [], [
                        'capability_results' => $capabilityResults,
                    ]);
                    $step->wait($reason)->save();
                    $execution->state_reason = $reason;
                    $execution->waitForApproval($reason)->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($termination['status'] === AgentExecution::STATUS_WAITING_FOR_INPUT) {
                    $step->wait($termination['reason'])->save();
                    $execution->waitForInput($termination['reason'])->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($termination['status'] === AgentExecution::STATUS_WAITING_FOR_APPROVAL) {
                    $step->wait($termination['reason'])->save();
                    $execution->waitForApproval($termination['reason'])->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($termination['status'] === AgentExecution::STATUS_DELEGATED) {
                    $step->wait($termination['reason'])->save();
                    $execution->markDelegated($termination['reason'])->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($termination['status'] === AgentExecution::STATUS_PAUSED) {
                    $step->wait($termination['reason'])->save();
                    $execution->pause($termination['reason'])->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                $step->complete()->save();
                $execution->save();

                if ($termination['status'] === AgentExecution::STATUS_COMPLETED) {
                    $execution->complete($termination['reason'])->save();
                    $this->consolidateMemory($memoryRuntime, $actor, $execution, $modelResult, $decision, $correlation);

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($sequence >= $execution->max_steps) {
                    $execution->complete('max_steps_reached')->save();
                    $this->consolidateMemory($memoryRuntime, $actor, $execution, $modelResult, $decision, $correlation);

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                $execution->next_step = $termination['next_step'] ?? 'Continue the current task.';
                $execution->beginReasoning()->save();
            }

            $execution->complete('max_steps_reached')->save();
            $this->consolidateMemory($memoryRuntime, $actor, $execution, $lastResult, $lastDecision, $correlation);

            return new AgentExecutionResult($execution->refresh(), $lastResult, $lastDecision, $authorizedRequests);
        } catch (Throwable $exception) {
            if (in_array($execution->status, [AgentExecution::STATUS_REASONING, AgentExecution::STATUS_EXECUTING], true)) {
                $error = ExecutionError::from($exception);
                $execution->failure_code = $error->code;
                $execution->fail($error->message)->save();
                $this->consolidateMemory($memoryRuntime, $actor, $execution, $lastResult, $lastDecision, $correlation);

                if ($step instanceof AgentExecutionStep && $step->status === AgentExecutionStep::STATUS_RUNNING) {
                    $step->fail($error->message, $error->code)->save();
                }

                $correlation->logFailure('agent.execute', $execution->correlation_id ?? $correlationId, $error, [
                    'actor_id' => $execution->actor_id,
                    'organization_id' => $execution->organization_id,
                    'enterprise_id' => $execution->enterprise_id,
                    'agent_assignment_id' => $execution->agent_assignment_id,
                    'execution_id' => $execution->getKey(),
                    'step' => $execution->current_step,
                    'provider' => $execution->provider,
                ]);
            }

            throw $exception;
        }
    }

    /** @return array{status: string, next_step: ?string, reason: ?string} */
    private function termination(ModelResult $result): array
    {
        $structured = $result->structured ?? [];
        $termination = $structured['termination'] ?? 'completed';

        $status = match ($termination) {
            'continue' => AgentExecution::STATUS_REASONING,
            'waiting_for_input' => AgentExecution::STATUS_WAITING_FOR_INPUT,
            'waiting_for_approval' => AgentExecution::STATUS_WAITING_FOR_APPROVAL,
            'delegated' => AgentExecution::STATUS_DELEGATED,
            'paused' => AgentExecution::STATUS_PAUSED,
            'completed' => AgentExecution::STATUS_COMPLETED,
            default => throw new AuthorizationException("The model returned an invalid execution termination state [{$termination}]."),
        };

        return [
            'status' => $status,
            'next_step' => isset($structured['next_step']) && is_string($structured['next_step']) ? trim($structured['next_step']) : null,
            'reason' => isset($structured['termination_reason']) && is_string($structured['termination_reason']) ? trim($structured['termination_reason']) : null,
        ];
    }

    /** @param array<string, mixed> $options */
    /**
     * @param  array<string, mixed>  $targetContext
     * @return array<string, mixed>
     */
    /** @return list<string> */
    private function requiredContext(Agent $agent): array
    {
        return array_values(array_unique([
            ...$agent->requiredContext(),
            'memory',
        ]));
    }

    private function consolidateMemory(
        AgentMemoryRuntimeService $memoryRuntime,
        User $actor,
        AgentExecution $execution,
        ?ModelResult $result,
        ?AgentDecision $decision,
        ExecutionCorrelationService $correlation,
    ): void {
        try {
            $memoryRuntime->consolidate($actor, $execution, $result, $decision);
        } catch (Throwable $memoryException) {
            $correlation->logFailure(
                'agent.memory.consolidate',
                $execution->correlation_id ?? 'unknown',
                ExecutionError::from($memoryException),
                [
                    'execution_id' => $execution->getKey(),
                    'organization_id' => $execution->organization_id,
                    'enterprise_id' => $execution->enterprise_id,
                    'agent_descriptor_id' => $execution->agent_descriptor_id,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $targetContext
     * @return array<string, mixed>
     */
    private function executionTargetContext(Agent $agent, array $targetContext, string $prompt): array
    {
        $requirements = $agent->requiredContext();

        if (array_intersect(['knowledge', 'retrieved_knowledge'], $requirements) && ! isset($targetContext['retrieved_knowledge'])) {
            $targetContext['retrieved_knowledge'] = [
                'query' => $prompt,
                'objective' => $prompt,
                'mode' => 'hybrid',
                'limit' => 5,
                'budget' => 1200,
            ];
        }

        $targetContext['memory'] ??= [
            'topic' => $prompt,
            'budget' => 20,
            'episodic_limit' => 10,
            'relevant_after' => now()->subDays(180)->toISOString(),
            'semantic_limit' => 10,
        ];

        return $targetContext;
    }

    /** @param array<string, mixed> $options */
    private function maxSteps(array $options): int
    {
        $value = $options['max_steps'] ?? 5;

        if (! is_int($value) || $value < 1) {
            throw new AuthorizationException('Agent execution max_steps must be a positive integer.');
        }

        return min($value, 10);
    }

    /**
     * @param  array<string, mixed>  $contextData
     * @return array<string, mixed>
     */
    private function stepContext(AgentExecution $execution, array $contextData): array
    {
        return array_merge($contextData, [
            'execution_id' => $execution->getKey(),
            'step' => $execution->current_step + 1,
            'previous_result' => $execution->last_result,
            'next_step' => $execution->next_step,
        ]);
    }

    private function resultFromExecution(AgentExecution $execution): AgentExecutionResult
    {
        $stored = is_array($execution->last_result) ? $execution->last_result : null;
        $modelResult = $stored === null ? null : new ModelResult(
            text: (string) ($stored['text'] ?? ''),
            structured: isset($stored['structured']) && is_array($stored['structured']) ? $stored['structured'] : null,
            provider: (string) ($stored['provider'] ?? $execution->provider ?? 'unknown'),
            model: (string) ($stored['model'] ?? 'unknown'),
            invocationId: (string) ($stored['invocation_id'] ?? $execution->external_execution_id ?? $execution->getKey()),
            usage: isset($stored['usage']) && is_array($stored['usage']) ? $stored['usage'] : [],
            correlationId: isset($stored['correlation_id']) && is_string($stored['correlation_id']) ? $stored['correlation_id'] : $execution->correlation_id,
        );

        return new AgentExecutionResult(
            execution: $execution->refresh(),
            modelResult: $modelResult,
            decision: AgentDecision::query()->where('execution_id', $execution->getKey())->latest('id')->first(),
            capabilityRequests: [],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $expertSlugs
     * @param  array<string, mixed>  $targetContext
     * @return array<string, mixed>
     */
    private function coordinateExperts(
        Agent $agent,
        array $context,
        array $expertSlugs,
        AgentAssignment $assignment,
        User $actor,
        AgentExecution $execution,
        string $businessObjective,
        array $targetContext,
    ): array {
        if ($expertSlugs === []) {
            return [];
        }

        $invocations = $this->expertInvocations ?? app(ExpertInvocationService::class);
        $results = [];
        $instructions = [];

        foreach ($expertSlugs as $slug) {
            $invocation = $invocations->invoke(new ExpertInvocationRequest(
                actor: $actor,
                assignment: $assignment,
                execution: $execution,
                agent: $agent,
                expertSlug: $slug,
                businessObjective: $businessObjective,
                authorizedContext: $context,
                expectedReasoningOutput: 'Provide specialized reasoning that informs the parent Agent decision without executing business operations.',
                targetContext: $targetContext,
                correlationId: $execution->correlation_id,
            ));

            if ($invocation->failed()) {
                throw new \RuntimeException($invocation->failure->message ?? 'Expert invocation failed.');
            }

            $results[] = [
                'expert' => $invocation->expertName,
                'result' => $invocation->reasoningOutput,
                'invocation' => $invocation->toArray(),
            ];

            $instructions[] = [
                'name' => $invocation->expertName,
                'responsibilities' => $agent->responsibilities(),
                'methodology' => $invocation->metadata['methodology'] ?? 'Specialized reasoning within the authorized Agent context.',
            ];
        }

        return [
            'agent' => $agent->name(),
            'results' => $results,
            'instructions' => $instructions,
        ];
    }

    /** @param array<string, mixed> $expertResults */
    private function instructions(Agent $agent, array $expertResults): string
    {
        $instructions = implode("\n", [
            'You are executing as the authorized CR8OR Agent runtime.',
            'Treat the supplied context as the complete authorization context.',
            'Instructions and model reasoning never grant permissions.',
            'Do not invent authority, capabilities, approvals, or context.',
            'Only request capabilities explicitly represented by the Agent assignment permissions.',
            'Only produce a decision/recommendation when the result is suitable for historical recording.',
            "Agent methodology: {$agent->description()}",
        ]);

        if ($expertResults !== []) {
            $instructions .= "\nExperts are advisory only and cannot grant authority.";
        }

        return $instructions;
    }

    /** @return array<string, mixed> */
    private function outputSchema(Agent $agent): array
    {
        return $agent->reasoningOutputSchema();
    }

    /**
     * @param  array<string, mixed>  $targetContext
     * @return list<CapabilityRequest>
     */
    private function authorizeCapabilityRequests(
        User $actor,
        AgentAssignment $assignment,
        Enterprise $enterprise,
        AgentExecution $execution,
        ModelResult $result,
        array $targetContext,
        ?AgentDelegation $delegation = null,
    ): array {
        $requests = $result->structured['capability_requests'] ?? [];

        if (! is_array($requests)) {
            return [];
        }

        /** @var list<CapabilityRequest> $authorized */
        $authorized = [];
        $capabilities = $this->capabilities ?? app(CapabilityRegistry::class);

        foreach ($requests as $encodedRequest) {
            if (is_string($encodedRequest)) {
                $request = json_decode($encodedRequest, true);

                if (! is_array($request)) {
                    throw new AuthorizationException('The model returned an invalid capability request.');
                }
            } else {
                $request = $encodedRequest;
            }

            if (! isset($request['capability']) || ! is_string($request['capability'])) {
                throw new AuthorizationException('The model returned an invalid capability request.');
            }

            $capability = $request['capability'];
            $definition = $capabilities->resolve($capability);
            $requestContext = isset($request['target_context']) && is_array($request['target_context'])
                ? $request['target_context']
                : $targetContext;
            $inputPayload = isset($request['input_payload']) && is_array($request['input_payload'])
                ? $request['input_payload']
                : [];
            $approval = isset($request['approval_request_id'])
                ? ApprovalRequest::query()->find((int) $request['approval_request_id'])
                : null;
            $capabilityRequest = new CapabilityRequest(
                capability: $capability,
                assignment: $assignment,
                execution: $execution,
                actor: $actor,
                targetContext: $requestContext,
                inputPayload: $inputPayload,
                approval: $approval,
                correlationId: isset($request['correlation_id']) && is_string($request['correlation_id'])
                    ? $request['correlation_id']
                    : $execution->correlation_id,
                idempotencyKey: isset($request['idempotency_key']) && is_string($request['idempotency_key'])
                    ? $request['idempotency_key']
                    : null,
                delegation: $delegation,
            );

            $permission = $assignment->permissions()
                ->where('capability', $capability)
                ->first();

            if ($permission === null) {
                throw new AuthorizationException("The Agent is not authorized for capability [{$capability}].");
            }

            if (! $permission->requires_approval || $approval !== null) {
                if (! $this->capabilityAuthorizer->allowsRequest($capabilityRequest)) {
                    throw new AuthorizationException("The Agent is not authorized for capability [{$capability}].");
                }
            }

            $authorized[] = $capabilityRequest;
        }

        return $authorized;
    }

    /**
     * @param  list<CapabilityRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function executeCapabilityRequests(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $executor = $this->capabilityExecution ?? app(CapabilityExecutionService::class);
        $results = [];

        foreach ($requests as $request) {
            $results[] = $executor->execute($request);
        }

        return $results;
    }

    private function persistDecision(AgentExecution $execution, ModelResult $result): ?AgentDecision
    {
        $title = $result->structured['decision_title'] ?? null;
        $summary = $result->structured['decision_summary'] ?? null;

        if (! is_string($title) || $title === '' || ! is_string($summary) || $summary === '') {
            return null;
        }

        return AgentDecision::query()->create([
            'organization_id' => $execution->organization_id,
            'enterprise_id' => $execution->enterprise_id,
            'execution_id' => $execution->getKey(),
            'agent_descriptor_id' => $execution->agent_descriptor_id,
            'actor_id' => $execution->actor_id,
            'organization_name' => $execution->organization_name,
            'enterprise_name' => $execution->enterprise_name,
            'agent_slug' => $execution->agent_slug,
            'agent_runtime_class' => $execution->agent_runtime_class,
            'actor_name' => $execution->actor_name,
            'title' => $title,
            'summary' => $summary,
            'rationale' => isset($result->structured['decision_rationale']) && is_string($result->structured['decision_rationale'])
                ? $result->structured['decision_rationale']
                : null,
            'decided_at' => now(),
        ]);
    }
}