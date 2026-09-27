<?php

namespace App\Services;

use App\Agents\Agent;
use App\AI\Contracts\ExecutionError;
use App\AI\Contracts\ModelProvider;
use App\AI\Data\AgentExecutionResult;
use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;
use App\Capabilities\CapabilityRegistry;
use App\Data\AgentContextSection;
use App\Data\AgentExecutionRequest;
use App\Data\CapabilityRequest;
use App\Data\ExpertInvocationRequest;
use App\Models\AgentAssignment;
use App\Models\AgentDecision;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
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
    ) {}

    public function execute(AgentExecutionRequest $request): AgentExecutionResult
    {
        $actor = $request->actor;
        $assignment = $request->assignment;
        $prompt = $request->prompt;
        $targetContext = $request->targetContext;
        $expertSlugs = $request->expertSlugs;
        $modelOptions = $request->options;
        $correlation = $this->correlation ?? app(ExecutionCorrelationService::class);
        $correlationId = $correlation->resolve($request->correlationId);

        $assignment->loadMissing(['agentDescriptor', 'organization', 'enterprise']);

        $delegation = $request->delegation;

        Gate::forUser($actor)->authorize('view', $assignment);

        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException('The Agent assignment is disabled.');
        }

        $enterprise = $assignment->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException('Agent execution requires an enterprise-scoped assignment.');
        }

        if ($enterprise->organization_id !== $assignment->organization_id) {
            throw new AuthorizationException('The Agent assignment enterprise does not belong to its organization.');
        }

        $descriptor = $assignment->agentDescriptor;
        $runtimeClass = $descriptor->resolveRuntimeClass();
        $agent = app($runtimeClass);

        if (! $agent instanceof Agent) {
            throw new AuthorizationException('The configured Agent runtime is invalid.');
        }

        if ($request->expertRoutingKey !== null) {
            $expertSlugs = $agent->expertsFor($request->expertRoutingKey);
        }

        $context = $this->contextAssembler->forAgent(
            $actor,
            $enterprise,
            $agent->requiredContext(),
            $targetContext,
            $assignment,
        );

        $contextData = $context->toArray();

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
            'status' => AgentExecution::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        try {
            $execution->start()->save();

            $expertResults = $this->coordinateExperts($agent, $contextData, $expertSlugs, $assignment, $actor, $execution, $prompt, $targetContext);

            $contextData = $context->withSection(new AgentContextSection(
                name: 'instructions',
                data: [
                    'agent' => [
                        'name' => $agent->name(),
                        'instructions' => $agent->instructions(),
                    ],
                    'experts' => $expertResults['instructions'] ?? [],
                ],
                source: get_class($agent),
                scope: [
                    'organization_id' => $assignment->organization_id,
                    'enterprise_id' => $enterprise->getKey(),
                    'agent_assignment_id' => $assignment->getKey(),
                ],
                relevance: 'Agent and Expert runtime instructions',
            ))->toArray();

            $request = new ModelRequest(
                prompt: $prompt,
                instructions: implode('
', [$agent->instructions(), $this->instructions($agent, $expertResults)]),
                context: array_merge($contextData, [
                    'execution_id' => $execution->getKey(),
                    'agent' => [
                        'slug' => $descriptor->slug,
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
                ]),
                provider: isset($modelOptions['provider']) ? (string) $modelOptions['provider'] : null,
                model: isset($modelOptions['model']) ? (string) $modelOptions['model'] : null,
                timeout: isset($modelOptions['timeout']) ? (int) $modelOptions['timeout'] : null,
                structuredOutputSchema: $this->outputSchema(),
                correlationId: $correlationId,
            );

            $modelResult = $this->provider->generate($request);
            $execution->provider = $modelResult->provider;
            $execution->external_execution_id = $modelResult->invocationId;
            $execution->save();
            $capabilityRequests = $this->authorizeCapabilityRequests(
                $actor,
                $assignment,
                $enterprise,
                $execution,
                $modelResult,
                $targetContext,
                $delegation,
            );

            $decision = $this->persistDecision($execution, $modelResult);

            $execution->succeed()->save();

            return new AgentExecutionResult(
                execution: $execution->refresh(),
                modelResult: $modelResult,
                decision: $decision,
                capabilityRequests: $capabilityRequests,
            );
        } catch (Throwable $exception) {
            if ($execution->status === AgentExecution::STATUS_EXECUTING) {
                $error = ExecutionError::from($exception);
                $execution->failure_code = $error->code;
                $execution->fail($error->message)->save();
                $correlation->logFailure('agent.execute', $execution->correlation_id ?? $correlationId, $error, [
                    'actor_id' => $execution->actor_id,
                    'organization_id' => $execution->organization_id,
                    'enterprise_id' => $execution->enterprise_id,
                    'agent_assignment_id' => $execution->agent_assignment_id,
                    'execution_id' => $execution->getKey(),
                    'provider' => $execution->provider,
                ]);
            }

            throw $exception;
        }
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
    private function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'decision_title' => ['type' => 'string'],
                'decision_summary' => ['type' => 'string'],
                'decision_rationale' => ['type' => 'string'],
                'capability_requests' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'capability' => ['type' => 'string'],
                            'target_context' => ['type' => 'object'],
                            'input_payload' => ['type' => 'object'],
                            'approval_request_id' => ['type' => 'integer'],
                            'correlation_id' => ['type' => 'string'],
                            'idempotency_key' => ['type' => 'string'],
                        ],
                        'required' => ['capability'],
                    ],
                ],
            ],
        ];
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
            if (! is_string($encodedRequest)) {
                throw new AuthorizationException('The model returned an invalid capability request.');
            }

            $request = json_decode($encodedRequest, true);

            if (! is_array($request) || ! isset($request['capability']) || ! is_string($request['capability'])) {
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

            if (! $this->capabilityAuthorizer->allowsRequest($capabilityRequest)) {
                throw new AuthorizationException("The Agent is not authorized for capability [{$capability}].");
            }

            $authorized[] = $capabilityRequest;
        }

        return $authorized;
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