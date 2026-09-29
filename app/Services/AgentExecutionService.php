<?php

namespace App\Services;

use App\Agents\Agent;
use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ModelProvider;
use App\AI\Data\AgentExecutionResult;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use App\AI\Exceptions\ModelProviderException;
use App\AI\Exceptions\ModelProviderFailureType;
use App\AI\ReasoningOutputValidator;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentDelegationRequest;
use App\Data\AgentExecutionRequest;
use App\Data\CapabilityRequest;
use App\Data\ExpertInvocationRequest;
use App\Enums\AgentExecutionMode;
use App\Events\AgentDelegated;
use App\Events\AgentExecutionCompleted;
use App\Events\AgentExecutionFailed;
use App\Events\AgentExecutionPaused;
use App\Events\AgentExecutionResumed;
use App\Events\AgentExecutionStarted;
use App\Events\AgentReasoningCompleted;
use App\Events\ExpertInvoked;
use App\Events\KnowledgeRetrieved;
use App\Jobs\RunAgentExecutionJob;
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
        private readonly ?InteractiveCapabilityStepRunner $interactiveSteps = null,
        private readonly ?AgentRuntimePolicyService $runtimePolicies = null,
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

        $runtimePolicies = $this->runtimePolicies ?? app(AgentRuntimePolicyService::class);
        $runtimePolicy = $runtimePolicies->resolveForAssignment($actor, $assignment);
        $runtimePolicies->assertCanExecute($runtimePolicy);
        $runtimeOptions = $runtimePolicies->enforceOptions($runtimePolicy, $request->options);

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
            $existing->assertMode($request->mode);
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
                $request->allowWorkerRetry,
            );
        }

        $executionTargetContext = $this->executionTargetContext($agent, $request->targetContext, $request->prompt, $runtimePolicy);

        $context = $this->contextAssembler->forAgent(
            $actor,
            $enterprise,
            $this->requiredContext($agent),
            $executionTargetContext,
            $assignment,
        );
        $runtimePolicies->enforceContextSize($runtimePolicy, $context->toArray());

        if ($request->mode === AgentExecutionMode::INTERACTIVE && $request->capabilityRequests !== []) {
            $runtimeContext = array_merge($context->toArray(), [
                'interactive_capability_requests' => $request->capabilityRequests,
            ]);
        } else {
            $runtimeContext = $context->toArray();
        }

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
            'mode' => $request->mode,
            'requested_at' => now(),
            'max_steps' => (int) $runtimeOptions['max_steps'],
            'current_step' => 0,
            'prompt' => $request->prompt,
            'target_context' => $executionTargetContext,
            'expert_slugs' => $expertSlugs,
            'max_retries' => (int) $runtimeOptions['max_retries'],
            'model_options' => $runtimeOptions,
            'runtime_policy' => $runtimePolicy,
            'runtime_policy_version' => hash('sha256', json_encode($runtimePolicy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'execution_context' => $runtimeContext,
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
            $request->allowWorkerRetry,
        );
    }

    public function queue(AgentExecutionRequest $request): AgentExecution
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

        $agent = app($assignment->agentDescriptor->resolveRuntimeClass());
        if (! $agent instanceof Agent) {
            throw new AuthorizationException('The configured Agent runtime is invalid.');
        }

        $runtimePolicies = $this->runtimePolicies ?? app(AgentRuntimePolicyService::class);
        $runtimePolicy = $runtimePolicies->resolveForAssignment($actor, $assignment);
        $runtimePolicies->assertCanExecute($runtimePolicy);
        $runtimeOptions = $runtimePolicies->enforceOptions($runtimePolicy, $request->options);

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
            $existing->assertMode($request->mode);
            if ($request->mode === AgentExecutionMode::AUTONOMOUS
                && ! in_array($existing->status, [AgentExecution::STATUS_COMPLETED, AgentExecution::STATUS_FAILED, AgentExecution::STATUS_CANCELLED], true)
                && ! in_array($existing->status, [
                    AgentExecution::STATUS_WAITING_FOR_INPUT,
                    AgentExecution::STATUS_WAITING_FOR_APPROVAL,
                    AgentExecution::STATUS_DELEGATED,
                    AgentExecution::STATUS_PAUSED,
                ], true)) {
                RunAgentExecutionJob::dispatch($existing->getKey(), $actor->getKey(), $request->delegation?->getKey());
            }

            return $existing->refresh();
        }

        $executionTargetContext = $this->executionTargetContext($agent, $request->targetContext, $request->prompt, $runtimePolicy);
        $context = $this->contextAssembler->forAgent(
            $actor,
            $enterprise,
            $this->requiredContext($agent),
            $executionTargetContext,
            $assignment,
        );
        $runtimePolicies->enforceContextSize($runtimePolicy, $context->toArray());

        $execution = AgentExecution::query()->create([
            'organization_id' => $assignment->organization_id,
            'enterprise_id' => $enterprise->getKey(),
            'agent_descriptor_id' => $assignment->agentDescriptor->getKey(),
            'agent_assignment_id' => $assignment->getKey(),
            'actor_id' => $actor->getKey(),
            'organization_name' => $assignment->organization->name,
            'enterprise_name' => $enterprise->name,
            'agent_slug' => $assignment->agentDescriptor->slug,
            'agent_runtime_class' => $assignment->agentDescriptor->runtime_class,
            'agent_definition_version' => $agent->definitionVersion(),
            'actor_name' => $actor->name,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'status' => AgentExecution::STATUS_REQUESTED,
            'mode' => $request->mode,
            'requested_at' => now(),
            'max_steps' => (int) $runtimeOptions['max_steps'],
            'current_step' => 0,
            'prompt' => $request->prompt,
            'target_context' => $executionTargetContext,
            'expert_slugs' => $expertSlugs,
            'max_retries' => (int) $runtimeOptions['max_retries'],
            'model_options' => $runtimeOptions,
            'runtime_policy' => $runtimePolicy,
            'runtime_policy_version' => hash('sha256', json_encode($runtimePolicy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'execution_context' => $context->toArray(),
        ]);

        if ($request->mode === AgentExecutionMode::AUTONOMOUS) {
            RunAgentExecutionJob::dispatch($execution->getKey(), $actor->getKey(), $request->delegation?->getKey());
        }

        return $execution;
    }

    public function queueResume(AgentExecution $execution, User $actor): AgentExecution
    {
        $execution->loadMissing(['agentAssignment.agentDescriptor', 'agentAssignment.organization', 'agentAssignment.enterprise']);

        if (! $execution->agentAssignment instanceof AgentAssignment) {
            throw new AuthorizationException('Agent execution cannot be resumed without its Agent assignment.');
        }

        Gate::forUser($actor)->authorize('view', $execution->agentAssignment);

        if ($execution->actor_id !== $actor->getKey()) {
            throw new AuthorizationException('Only the execution actor may resume this Agent execution.');
        }

        if ($execution->mode === AgentExecutionMode::INTERACTIVE) {
            $this->resume($execution, $actor);
        } else {
            RunAgentExecutionJob::dispatch($execution->getKey(), $actor->getKey(), null, true);
        }

        return $execution->refresh();
    }

    public function cancel(AgentExecution $execution, User $actor, ?string $reason = null): AgentExecution
    {
        $execution->loadMissing(['agentAssignment.agentDescriptor']);

        if (! $execution->agentAssignment instanceof AgentAssignment) {
            throw new AuthorizationException('Agent execution cannot be cancelled without its Agent assignment.');
        }

        Gate::forUser($actor)->authorize('view', $execution->agentAssignment);

        if ($execution->actor_id !== $actor->getKey()) {
            throw new AuthorizationException('Only the execution actor may cancel this Agent execution.');
        }

        if (! in_array($execution->status, [
            AgentExecution::STATUS_COMPLETED,
            AgentExecution::STATUS_FAILED,
            AgentExecution::STATUS_CANCELLED,
        ], true)) {
            $execution->cancel($reason ?? 'Cancelled by the execution actor.')->save();
        }

        return $execution->refresh();
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

        $runtimePolicies = $this->runtimePolicies ?? app(AgentRuntimePolicyService::class);
        $runtimePolicy = $runtimePolicies->resolveForAssignment($actor, $assignment);
        $runtimePolicies->assertCanExecute($runtimePolicy);

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

        app(AgentExecutionEventService::class)->dispatch(AgentExecutionResumed::class, $execution, data: [
            'status' => $execution->status,
        ]);

        if ($execution->status === AgentExecution::STATUS_DELEGATED) {
            if (! $this->refreshDelegatedResults($execution)) {
                return $this->resultFromExecution($execution);
            }
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
        bool $allowWorkerRetry = false,
    ): AgentExecutionResult {
        $correlation = $this->correlation ?? app(ExecutionCorrelationService::class);
        $memoryRuntime = $this->memoryRuntime ?? app(AgentMemoryRuntimeService::class);
        $lastResult = null;
        $lastDecision = null;
        $authorizedRequests = [];
        $step = null;

        $events = app(AgentExecutionEventService::class);

        try {
            if ($execution->mode === AgentExecutionMode::INTERACTIVE) {
                return $this->runInteractive(
                    $execution,
                    $actor,
                    $assignment,
                    $enterprise,
                    $correlationId,
                );
            }

            if ($execution->status === AgentExecution::STATUS_REQUESTED) {
                $execution->start()->save();
                $events->dispatch(AgentExecutionStarted::class, $execution, data: [
                    'step' => 1,
                    'mode' => $execution->mode->value,
                    'correlation_id' => $execution->correlation_id,
                ]);

                if (isset($contextData['knowledge']) || isset($contextData['retrieved_knowledge'])) {
                    $events->dispatch(KnowledgeRetrieved::class, $execution, data: [
                        'knowledge_count' => is_array($contextData['knowledge']['items'] ?? null) ? count($contextData['knowledge']['items']) : 0,
                        'retrieved_count' => is_array($contextData['retrieved_knowledge']['items'] ?? null) ? count($contextData['retrieved_knowledge']['items']) : 0,
                    ]);
                }
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

                $stepWasRunning = $step->status === AgentExecutionStep::STATUS_RUNNING;
                $step->intent = $execution->next_step ?? ($sequence === 1 ? $prompt : 'Continue the current task using the prior structured result.');
                $step->input_context = $this->stepContext($execution, $contextData);
                if (! $stepWasRunning) {
                    $step->start()->save();
                }
                $execution->current_step = $sequence;
                $execution->beginReasoning()->save();

                $persistedModel = $stepWasRunning && is_array($step->output['model_result'] ?? null)
                    ? $step->output['model_result']
                    : null;

                $expertResults = $persistedModel === null
                    ? $this->coordinateExperts(
                        $agent,
                        $contextData,
                        $expertSlugs,
                        $assignment,
                        $actor,
                        $execution,
                        $step->intent,
                        $targetContext,
                    )
                    : [];

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

                $persistedModel = $stepWasRunning && is_array($step->output['model_result'] ?? null)
                    ? $step->output['model_result']
                    : null;

                if ($persistedModel !== null) {
                    $modelResult = new ModelResult(
                        text: (string) ($persistedModel['text'] ?? ''),
                        structured: is_array($persistedModel['structured'] ?? null) ? $persistedModel['structured'] : null,
                        provider: (string) ($persistedModel['provider'] ?? 'unknown'),
                        model: (string) ($persistedModel['model'] ?? 'unknown'),
                        invocationId: (string) ($persistedModel['invocation_id'] ?? ''),
                        usage: is_array($persistedModel['usage'] ?? null) ? $persistedModel['usage'] : [],
                        correlationId: isset($persistedModel['correlation_id']) && is_string($persistedModel['correlation_id']) ? $persistedModel['correlation_id'] : $correlationId,
                    );
                } else {
                    $modelResult = $this->generateModelWithFallback($modelRequest, $modelOptions);
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
                }

                $step->output = array_merge($step->output ?? [], [
                    'model_result' => [
                        'text' => $modelResult->text,
                        'structured' => $modelResult->structured,
                        'provider' => $modelResult->provider,
                        'model' => $modelResult->model,
                        'invocation_id' => $modelResult->invocationId,
                        'usage' => $modelResult->usage,
                        'correlation_id' => $modelResult->correlationId,
                    ],
                ]);
                $step->save();
                $events->dispatch(AgentReasoningCompleted::class, $execution, data: [
                    'step' => $sequence,
                    'provider' => $modelResult->provider,
                    'model' => $modelResult->model,
                    'invocation_id' => $modelResult->invocationId,
                    'usage' => $modelResult->usage,
                ]);

                $previousCapabilityResults = is_array($execution->last_result['capability_results'] ?? null)
                    ? $execution->last_result['capability_results']
                    : [];
                $previousDelegationResults = is_array($execution->last_result['delegation_results'] ?? null)
                    ? $execution->last_result['delegation_results']
                    : [];

                $execution->provider = $modelResult->provider;
                $execution->external_execution_id = $modelResult->invocationId;
                $execution->last_result = [
                    'expert_results' => array_map(static function (array $result): array {
                        $invocation = is_array($result['invocation'] ?? null) ? $result['invocation'] : [];

                        return [
                            'expert' => $result['expert'] ?? null,
                            'status' => $invocation['status'] ?? 'succeeded',
                            'correlation_id' => $invocation['correlation_id'] ?? null,
                            'requested_capabilities' => $invocation['requested_capabilities'] ?? [],
                            'decisions' => $invocation['decisions'] ?? [],
                            'recommendations' => $invocation['recommendations'] ?? [],
                            'metadata' => $invocation['metadata'] ?? [],
                        ];
                    }, is_array($expertResults['results'] ?? null) ? $expertResults['results'] : []),
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
                );

                $capabilityResults = $this->executeCapabilityRequests(
                    $authorizedRequests,
                    $step,
                );

                $delegationResults = $this->executeDelegationRequests(
                    $actor,
                    $assignment,
                    $execution,
                    $modelResult,
                    $targetContext,
                    $step,
                );

                $execution->last_result = array_merge($execution->last_result ?? [], [
                    'capability_results' => array_merge($previousCapabilityResults, $capabilityResults),
                    'delegation_results' => array_merge($previousDelegationResults, $delegationResults),
                ]);
                $step->output = array_merge($step->output ?? [], [
                    'capability_results' => $capabilityResults,
                    'delegation_results' => $delegationResults,
                ]);
                $step->save();
                $execution->save();

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
                    static fn (array $result): bool => ($result['status']) === 'waiting',
                );

                $delegationWait = collect($delegationResults)->first(
                    static fn (array $result): bool => in_array($result['execution_status'] ?? null, [
                        AgentExecution::STATUS_WAITING_FOR_INPUT,
                        AgentExecution::STATUS_WAITING_FOR_APPROVAL,
                        AgentExecution::STATUS_DELEGATED,
                        AgentExecution::STATUS_PAUSED,
                    ], true),
                );

                if (is_array($approvalWait)) {
                    $reason = 'Approval required for capability ['.($approvalWait['capability'] ?? 'unknown').'].';
                    $step->output = array_merge($step->output ?? [], [
                        'capability_results' => $capabilityResults,
                        'delegation_results' => $delegationResults,
                    ]);
                    $step->wait($reason)->save();
                    $execution->state_reason = $reason;
                    $execution->waitForApproval($reason)->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if (is_array($delegationWait)) {
                    $reason = 'Delegated Agent execution requires completion before the parent can continue.';
                    $step->output = array_merge($step->output ?? [], [
                        'delegation_results' => $delegationResults,
                    ]);
                    $step->wait($reason)->save();
                    $execution->markDelegated($reason)->save();

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($delegationResults !== [] && $termination['status'] === AgentExecution::STATUS_DELEGATED) {
                    $execution->next_step = $termination['next_step'] ?? 'Continue the current task using the delegated results.';
                    $step->output = array_merge($step->output ?? [], [
                        'delegation_results' => $delegationResults,
                    ]);
                    $step->complete()->save();
                    $execution->save();

                    if ($sequence >= $execution->max_steps) {
                        $execution->complete('max_steps_reached')->save();
                        $events->dispatch(AgentExecutionCompleted::class, $execution, data: ['reason' => 'max_steps_reached']);
                        $this->consolidateMemory($memoryRuntime, $actor, $execution, $modelResult, $decision, $correlation);

                        return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                    }

                    $execution->beginReasoning()->save();

                    continue;
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
                    $events->dispatch(AgentExecutionPaused::class, $execution, data: ['reason' => $termination['reason']]);

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                $step->complete()->save();
                $execution->save();

                if ($termination['status'] === AgentExecution::STATUS_COMPLETED) {
                    $execution->complete($termination['reason'])->save();
                    $events->dispatch(AgentExecutionCompleted::class, $execution, data: ['reason' => $termination['reason']]);
                    $this->consolidateMemory($memoryRuntime, $actor, $execution, $modelResult, $decision, $correlation);

                    return new AgentExecutionResult($execution->refresh(), $modelResult, $decision, $authorizedRequests);
                }

                if ($sequence >= $execution->max_steps) {
                    $execution->complete('max_steps_reached')->save();
                    $events->dispatch(AgentExecutionCompleted::class, $execution, data: ['reason' => 'max_steps_reached']);
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
                $error = ExecutionError::from($exception, correlationId: $execution->correlation_id ?? $correlationId);
                $failurePolicy = app(AgentFailurePolicy::class);
                $classification = $failurePolicy->classify($error);
                $execution->failure_category = $classification['category']->value;
                $execution->failure_code = $error->code;
                $execution->failure_provenance = $error->provenance->toArray();
                $execution->recordFailure($error, 'agent.execute');

                if ($allowWorkerRetry && $classification['retryable']) {
                    $execution->retry_count = min($execution->retry_count + 1, $execution->max_retries);
                    $execution->state_reason = $error->message;
                    $execution->save();

                    if ($execution->retry_count >= $execution->max_retries) {
                        $execution->fail('Retry limit reached: '.$error->message)->save();
                        $events->dispatch(AgentExecutionFailed::class, $execution, data: [
                            'failure_category' => $classification['category']->value,
                            'failure_code' => $error->code,
                            'retryable' => false,
                            'retry_count' => $execution->retry_count,
                        ]);
                        throw $exception;
                    }
                    $execution->state_reason = $error->message;
                    $execution->save();

                    $correlation->logFailure('agent.execute.retry', $execution->correlation_id ?? $correlationId, $error, [
                        'actor_id' => $execution->actor_id,
                        'organization_id' => $execution->organization_id,
                        'enterprise_id' => $execution->enterprise_id,
                        'agent_assignment_id' => $execution->agent_assignment_id,
                        'execution_id' => $execution->getKey(),
                        'step' => $execution->current_step,
                        'provider' => $execution->provider,
                    ]);

                    throw $exception;
                }

                $execution->fail($error->message)->save();
                $events->dispatch(AgentExecutionFailed::class, $execution, data: [
                    'failure_category' => $classification['category']->value,
                    'failure_code' => $error->code,
                    'retryable' => false,
                    'retry_count' => $execution->retry_count,
                ]);
                $this->consolidateMemory($memoryRuntime, $actor, $execution, $lastResult, $lastDecision, $correlation);

                if ($step instanceof AgentExecutionStep && $step->status === AgentExecutionStep::STATUS_RUNNING) {
                    $step->failure_provenance = $error->provenance->toArray();
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

    /**
     * Execute the persisted interactive Capability workflow without invoking a ModelProvider.
     */
    private function runInteractive(
        AgentExecution $execution,
        User $actor,
        AgentAssignment $assignment,
        Enterprise $enterprise,
        string $correlationId,
    ): AgentExecutionResult {
        $execution->assertMode(AgentExecutionMode::INTERACTIVE);
        $events = app(AgentExecutionEventService::class);

        if ($execution->status === AgentExecution::STATUS_REQUESTED) {
            $execution->start()->save();
            $events->dispatch(AgentExecutionStarted::class, $execution, data: [
                'step' => 1,
                'mode' => AgentExecutionMode::INTERACTIVE->value,
                'correlation_id' => $correlationId,
            ]);
        } elseif (! in_array($execution->status, [
            AgentExecution::STATUS_REASONING,
            AgentExecution::STATUS_EXECUTING,
            AgentExecution::STATUS_WAITING_FOR_INPUT,
            AgentExecution::STATUS_WAITING_FOR_APPROVAL,
            AgentExecution::STATUS_DELEGATED,
            AgentExecution::STATUS_PAUSED,
        ], true)) {
            $execution->beginReasoning()->save();
        }

        $payload = is_array($execution->execution_context['interactive_capability_requests'] ?? null)
            ? array_values($execution->execution_context['interactive_capability_requests'])
            : [];
        /**  list<array<string, mixed>> $payload */
        $runner = $this->interactiveSteps ?? app(InteractiveCapabilityStepRunner::class);
        $workflow = $runner->run($execution, $actor, $assignment, $enterprise, $correlationId, $payload);

        if ($workflow['requests'] === []) {
            return new AgentExecutionResult($execution->refresh(), null, null, []);
        }

        if ($execution->status === AgentExecution::STATUS_WAITING_FOR_APPROVAL) {
            return new AgentExecutionResult($execution->refresh(), null, null, $workflow['requests']);
        }

        $events->dispatch(AgentExecutionCompleted::class, $execution, data: [
            'mode' => AgentExecutionMode::INTERACTIVE->value,
            'reason' => 'interactive_capability_plan_completed',
            'steps' => $execution->current_step,
            'correlation_id' => $correlationId,
        ]);

        return new AgentExecutionResult($execution->refresh(), null, null, $workflow['requests']);
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
     * @param  array<string, mixed>  $runtimePolicy
     * @return array<string, mixed>
     */
    private function executionTargetContext(Agent $agent, array $targetContext, string $prompt, array $runtimePolicy = []): array
    {
        $requirements = $agent->requiredContext();

        if (array_intersect(['knowledge', 'retrieved_knowledge'], $requirements) && ! isset($targetContext['retrieved_knowledge'])) {
            $targetContext['retrieved_knowledge'] = [
                'query' => $prompt,
                'objective' => $prompt,
                'mode' => 'hybrid',
                'limit' => (int) ($runtimePolicy['retrieved_knowledge_limit'] ?? 5),
                'budget' => min(1200, (int) ($runtimePolicy['max_context_bytes'] ?? 120000) / 100),
            ];
        }

        $targetContext['memory'] ??= [
            'topic' => $prompt,
            'budget' => (int) ($runtimePolicy['memory_limit'] ?? 20),
            'episodic_limit' => min(10, (int) ($runtimePolicy['memory_limit'] ?? 20)),
            'relevant_after' => now()->subDays(180)->toISOString(),
            'semantic_limit' => min(10, (int) ($runtimePolicy['memory_limit'] ?? 20)),
        ];

        return $targetContext;
    }

    /** @param array<string, mixed> $modelOptions */
    private function generateModelWithFallback(ModelRequest $request, array $modelOptions): ModelResult
    {
        $providers = array_values(array_unique(array_filter([
            $request->provider,
            ...(array) ($modelOptions['fallback_providers'] ?? []),
        ])));

        $lastFailure = null;

        foreach ($providers === [] ? [null] : $providers as $provider) {
            try {
                return $this->provider->generate(new ModelRequest(
                    prompt: $request->prompt,
                    instructions: $request->instructions,
                    context: $request->context,
                    provider: $provider,
                    model: $request->model,
                    timeout: $request->timeout,
                    structuredOutputSchema: $request->structuredOutputSchema,
                    correlationId: $request->correlationId,
                ));
            } catch (ModelProviderException $exception) {
                $lastFailure = $exception;
                if (! in_array($exception->type, [ModelProviderFailureType::Unavailable, ModelProviderFailureType::RateLimited], true)) {
                    throw $exception;
                }
            }
        }

        throw $lastFailure;
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

            app(AgentExecutionEventService::class)->dispatch(ExpertInvoked::class, $execution, provenance: [
                'expert' => $invocation->expertName,
            ], data: [
                'expert' => $invocation->expertName,
                'correlation_id' => $invocation->correlationId,
            ]);

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
            'Only request capabilities explicitly owned by the authorized Expert.',
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

        foreach ($requests as $requestIndex => $encodedRequest) {
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
            $capabilities->resolve($capability);
            $requestContext = isset($request['target_context']) && is_array($request['target_context'])
                ? $request['target_context']
                : $targetContext;
            $inputPayload = isset($request['input_payload']) && is_array($request['input_payload'])
                ? $request['input_payload']
                : [];
            $approval = isset($request['approval_request_id'])
                ? ApprovalRequest::query()->find((int) $request['approval_request_id'])
                : null;
            $idempotencyKey = isset($request['idempotency_key']) && is_string($request['idempotency_key'])
                ? $request['idempotency_key']
                : hash('sha256', implode('|', [$execution->idempotency_key, $execution->current_step, $requestIndex, $capability]));

            $expertSlug = $request['expert_slug'] ?? null;
            if (! is_string($expertSlug) || trim($expertSlug) === '') {
                throw new AuthorizationException('The model returned a Capability request without Expert provenance.');
            }

            $capabilityRequest = new CapabilityRequest(
                capability: $capability,
                assignment: $assignment,
                execution: $execution,
                actor: $actor,
                targetContext: $requestContext,
                inputPayload: $inputPayload,
                expertSlug: $expertSlug,
                approval: $approval,
                correlationId: isset($request['correlation_id']) && is_string($request['correlation_id'])
                    ? $request['correlation_id']
                    : $execution->correlation_id,
                idempotencyKey: $idempotencyKey,
                delegation: $delegation,
            );

            if (! $this->capabilityAuthorizer->allowsRequest(
                $capabilityRequest,
                $approval === null,
            )) {
                throw new AuthorizationException("The Agent is not authorized for capability [{$capability}] through Expert [{$expertSlug}].");
            }

            $authorized[] = $capabilityRequest;
        }

        return $authorized;
    }

    private function refreshDelegatedResults(AgentExecution $execution): bool
    {
        $delegations = AgentDelegation::query()
            ->where('parent_agent_execution_id', $execution->getKey())
            ->with('targetAgentExecution')
            ->orderBy('id')
            ->get();

        if ($delegations->isEmpty()) {
            return true;
        }

        $results = [];

        foreach ($delegations as $delegation) {
            $child = $delegation->targetAgentExecution;

            if ($child === null) {
                return false;
            }

            if ($child->status === AgentExecution::STATUS_FAILED) {
                $failureCode = $child->failure_code ?? 'internal.unexpected';
                $failureCategory = $child->failure_category ?? 'non_retryable';
                $childProvenance = is_array($child->failure_provenance) ? $child->failure_provenance : [];
                $childHistory = is_array($child->failure_history) ? $child->failure_history : [];

                $execution->failure_code = $failureCode;
                $execution->failure_category = $failureCategory;
                $execution->failure_provenance = array_merge($childProvenance, [
                    'parent_execution_id' => $execution->getKey(),
                    'delegation_id' => $delegation->getKey(),
                ]);
                if ($childHistory !== []) {
                    $execution->failure_history = array_merge(
                        is_array($execution->failure_history) ? $execution->failure_history : [],
                        array_map(
                            static fn (array $failure): array => array_merge($failure, [
                                'source' => 'delegated_child',
                                'child_execution_id' => $child->getKey(),
                            ]),
                            $childHistory,
                        ),
                    );
                }
                $execution->fail('Delegated Agent execution failed: '.$failureCode)->save();

                return false;
            }

            if (! in_array($child->status, [AgentExecution::STATUS_COMPLETED, AgentExecution::STATUS_SUCCEEDED], true)) {
                return false;
            }

            $results[] = [
                'delegation_id' => $delegation->getKey(),
                'target_agent' => $delegation->target_agent_slug,
                'capability' => $delegation->capability,
                'status' => $delegation->status,
                'correlation_id' => $delegation->correlation_id,
                'execution_id' => $child->getKey(),
                'execution_status' => $child->status,
                'result' => is_array($child->last_result) ? $child->last_result : null,
                'provenance' => [
                    'parent_execution_id' => $execution->getKey(),
                    'delegation_id' => $delegation->getKey(),
                    'source_assignment_id' => $delegation->source_agent_assignment_id,
                    'target_assignment_id' => $delegation->target_agent_assignment_id,
                    'capability' => $delegation->capability,
                    'correlation_id' => $delegation->correlation_id,
                ],
            ];
        }

        $lastResult = is_array($execution->last_result) ? $execution->last_result : [];
        $execution->last_result = array_merge($lastResult, ['delegation_results' => $results]);
        $execution->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $targetContext
     * @return list<array<string, mixed>>
     */
    private function executeDelegationRequests(
        User $actor,
        AgentAssignment $assignment,
        AgentExecution $execution,
        ModelResult $result,
        array $targetContext,
        AgentExecutionStep $step,
    ): array {
        $requests = $result->structured['delegation_requests'] ?? [];

        if (! is_array($requests) || $requests === []) {
            return [];
        }

        $delegations = app(AgentDelegationService::class);
        $existing = is_array($step->output['delegation_results'] ?? null) ? $step->output['delegation_results'] : [];
        $results = [];

        foreach ($requests as $encodedRequest) {
            if (is_string($encodedRequest)) {
                $encodedRequest = json_decode($encodedRequest, true);
            }

            if (! is_array($encodedRequest)) {
                throw new AuthorizationException('The model returned an invalid delegation request.');
            }

            $targetAgentSlug = $encodedRequest['target_agent_slug'] ?? null;
            $capability = $encodedRequest['capability'] ?? null;
            $prompt = $encodedRequest['prompt'] ?? $encodedRequest['objective'] ?? null;
            $expertSlugs = $encodedRequest['expert_slugs'] ?? (isset($encodedRequest['expert_slug']) ? [$encodedRequest['expert_slug']] : []);
            $requestedContext = $encodedRequest['target_context'] ?? $targetContext;
            $contextRequirements = $encodedRequest['context_requirements'] ?? [];
            $idempotencyKey = $encodedRequest['idempotency_key'] ?? null;

            if (! is_string($targetAgentSlug) || trim($targetAgentSlug) === '') {
                throw new AuthorizationException('The model returned a delegation request without a target Agent.');
            }

            if (! is_string($capability) || trim($capability) === '') {
                throw new AuthorizationException('The model returned a delegation request without a target capability.');
            }

            if (! is_string($prompt) || trim($prompt) === '') {
                throw new AuthorizationException('The model returned a delegation request without an objective.');
            }

            if (! is_array($requestedContext)) {
                throw new AuthorizationException('The model returned invalid delegated target context.');
            }

            if (! is_array($contextRequirements) || array_filter($contextRequirements, static fn (mixed $item): bool => ! is_string($item) || trim($item) === '') !== []) {
                throw new AuthorizationException('The model returned invalid delegated context requirements.');
            }

            if (! is_array($expertSlugs) || array_filter($expertSlugs, static fn (mixed $item): bool => ! is_string($item) || trim($item) === '') !== []) {
                throw new AuthorizationException('The model returned invalid delegated Expert provenance.');
            }

            $expertSlugs = array_values(array_unique(array_map(static fn (string $slug): string => trim($slug), $expertSlugs)));
            if ($expertSlugs === []) {
                throw new AuthorizationException('The model returned a delegation request without Expert provenance.');
            }

            if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
                throw new AuthorizationException('The model returned a delegation request without an idempotency key.');
            }

            $existingResult = collect($existing)->first(
                static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['idempotency_key'] ?? null) === trim($idempotencyKey),
            );

            if (is_array($existingResult)) {
                $results[] = $existingResult;

                continue;
            }

            $sourceApproval = isset($encodedRequest['source_approval_request_id'])
                ? ApprovalRequest::query()->find((int) $encodedRequest['source_approval_request_id'])
                : null;
            $targetApproval = isset($encodedRequest['target_approval_request_id'])
                ? ApprovalRequest::query()->find((int) $encodedRequest['target_approval_request_id'])
                : null;

            $response = $delegations->delegate(new AgentDelegationRequest(
                actor: $actor,
                sourceAssignment: $assignment,
                targetAgentSlug: trim($targetAgentSlug),
                capability: trim($capability),
                prompt: trim($prompt),
                targetContext: $requestedContext,
                expertSlugs: $expertSlugs,
                contextRequirements: array_values($contextRequirements),
                sourceApproval: $sourceApproval,
                targetApproval: $targetApproval,
                correlationId: isset($encodedRequest['correlation_id']) && is_string($encodedRequest['correlation_id'])
                    ? $encodedRequest['correlation_id']
                    : $execution->correlation_id,
                idempotencyKey: trim($idempotencyKey),
                parentExecution: $execution,
            ));

            $childExecution = $response->execution;
            $childResult = $childExecution?->last_result;

            app(AgentExecutionEventService::class)->dispatch(AgentDelegated::class, $execution, provenance: [
                'delegation_id' => $response->delegation->getKey(),
                'target_assignment_id' => $response->targetAssignment->getKey(),
            ], data: [
                'target_agent' => $response->targetDescriptor->slug,
                'capability' => $response->capability,
                'child_execution_id' => $childExecution?->getKey(),
            ]);

            $results[] = [
                'delegation_id' => $response->delegation->getKey(),
                'target_agent' => $response->targetDescriptor->slug,
                'capability' => $response->capability,
                'idempotency_key' => trim($idempotencyKey),
                'status' => $response->delegation->status,
                'correlation_id' => $response->correlationId,
                'execution_id' => $childExecution?->getKey(),
                'execution_status' => $childExecution?->status,
                'result' => is_array($childResult) ? $childResult : null,
                'provenance' => [
                    'parent_execution_id' => $execution->getKey(),
                    'delegation_id' => $response->delegation->getKey(),
                    'source_assignment_id' => $assignment->getKey(),
                    'target_assignment_id' => $response->targetAssignment->getKey(),
                    'capability' => $response->capability,
                    'correlation_id' => $response->correlationId,
                ],
            ];
        }

        return $results;
    }

    /**
     * @param  list<CapabilityRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function executeCapabilityRequests(array $requests, AgentExecutionStep $step): array
    {
        if ($requests === []) {
            return [];
        }

        $executor = $this->capabilityExecution ?? app(CapabilityExecutionService::class);
        $existing = is_array($step->output['capability_results'] ?? null) ? $step->output['capability_results'] : [];
        $results = [];

        foreach ($requests as $request) {
            $existingResult = collect($existing)->first(
                static fn (mixed $result): bool => is_array($result) && ($result['idempotency_key'] ?? null) === $request->idempotencyKey,
            );

            if (is_array($existingResult)) {
                $results[] = $existingResult;

                continue;
            }

            $result = $executor->execute($request);
            $result['idempotency_key'] = $request->idempotencyKey;
            $results[] = $result;
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