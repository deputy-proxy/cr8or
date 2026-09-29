<?php

namespace App\Services;

use App\AI\Contracts\ExecutionError;
use App\AI\ReasoningOutputValidator;
use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityRequest;
use App\Data\ExpertInvocationRequest;
use App\Data\ExpertInvocationResult;
use App\Experts\Expert;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class ExpertInvocationService
{
    public function __construct(
        private readonly AgentCapabilityAuthorizer $capabilityAuthorizer,
        private readonly CapabilityRegistry $capabilities,
        private readonly ?ExecutionCorrelationService $correlation = null,
        private readonly ?AgentRuntimePolicyService $runtimePolicies = null,
    ) {}

    public function invoke(ExpertInvocationRequest $request): ExpertInvocationResult
    {
        $correlationId = ($this->correlation ?? app(ExecutionCorrelationService::class))->resolve(
            $request->correlationId ?? $request->execution->correlation_id,
        );

        $descriptor = null;
        $failureExpertSlug = $request->expertSlug;
        $failureExpertName = $request->expertSlug;
        $failureRuntimeClass = '';
        $runtime = null;

        try {
            $this->assertExecutionScope($request);

            if (! in_array($request->expertSlug, $request->agent->experts(), true)) {
                throw new AuthorizationException(sprintf(
                    'Expert [%s] is not declared by Agent [%s].',
                    $request->expertSlug,
                    $request->agent->name(),
                ));
            }

            $descriptor = ExpertDescriptor::query()->where('slug', $request->expertSlug)->first();

            if ($descriptor === null) {
                throw new AuthorizationException(sprintf('Expert [%s] could not be resolved.', $request->expertSlug));
            }

            $failureExpertSlug = $descriptor->slug;
            $failureRuntimeClass = $descriptor->runtime_class;

            $runtimePolicies = $this->runtimePolicies ?? app(AgentRuntimePolicyService::class);
            $runtimePolicy = $runtimePolicies->resolveForAssignment($request->actor, $request->assignment, $descriptor);
            $runtimePolicies->assertCanExecute($runtimePolicy);

            if (! $descriptor->enabled) {
                throw new AuthorizationException(sprintf('Expert [%s] is disabled.', $request->expertSlug));
            }
            $runtime = app($descriptor->resolveRuntimeClass());

            if (! $runtime instanceof Expert) {
                throw new AuthorizationException(sprintf('Expert [%s] has an invalid runtime.', $request->expertSlug));
            }

            $failureExpertName = $runtime->name();

            $missingContext = array_values(array_diff(
                $runtime->requiredContext(),
                array_keys($request->authorizedContext),
            ));

            if ($missingContext !== []) {
                throw new AuthorizationException(sprintf(
                    'Expert [%s] is missing required context: %s.',
                    $request->expertSlug,
                    implode(', ', $missingContext),
                ));
            }

            foreach ($runtime->capabilities() as $capability) {
                $this->capabilities->resolve($capability);

                if (! $this->capabilityAuthorizer->allowsExpertCapability(
                    $request->assignment,
                    $request->expertSlug,
                    $runtime,
                    $capability,
                    $request->assignment->organization,
                    $request->assignment->enterprise,
                    $request->actor,
                    null,
                    $request->execution,
                    $request->targetContext,
                    null,
                    true,
                )) {
                    throw new AuthorizationException(sprintf(
                        'The Agent is not authorized to use capability [%s] through Expert [%s].',
                        $capability,
                        $request->expertSlug,
                    ));
                }
            }

            $expertContext = array_intersect_key(
                $request->authorizedContext,
                array_fill_keys($runtime->requiredContext(), true),
            );

            $reasoningOutput = $runtime->analyze(array_merge($expertContext, [
                'invocation' => [
                    'business_objective' => $request->businessObjective,
                    'expected_reasoning_output' => $request->expectedReasoningOutput,
                ],
            ]));
            $reasoningOutput = ReasoningOutputValidator::normalizeExpert($reasoningOutput);

            $requestedCapabilities = $this->requestedCapabilities(
                $reasoningOutput,
                $request,
                $runtime,
            );

            $decisions = $this->listOfArrays($reasoningOutput['decisions'] ?? []);
            $recommendations = $this->listOfArrays($reasoningOutput['recommendations'] ?? []);

            return new ExpertInvocationResult(
                status: 'succeeded',
                expertSlug: $descriptor->slug,
                expertName: $runtime->name(),
                runtimeClass: $descriptor->runtime_class,
                agentExecutionId: $request->execution->getKey(),
                correlationId: $correlationId,
                businessObjective: $request->businessObjective,
                reasoningOutput: $reasoningOutput,
                requestedCapabilities: $requestedCapabilities,
                decisions: $decisions,
                recommendations: $recommendations,
                metadata: [
                    'required_context' => $runtime->requiredContext(),
                    'authorized_context' => array_keys($expertContext),
                    'declared_capabilities' => $runtime->capabilities(),
                    'responsibilities' => $runtime->responsibilities(),
                    'methodology' => $runtime->methodology(),
                    'expected_reasoning_output' => $request->expectedReasoningOutput,
                ],
            );
        } catch (Throwable $exception) {
            if ($exception instanceof AuthorizationException) {
                throw $exception;
            }

            $error = ExecutionError::from($exception);

            return new ExpertInvocationResult(
                status: 'failed',
                expertSlug: $failureExpertSlug,
                expertName: $failureExpertName,
                runtimeClass: $failureRuntimeClass,
                agentExecutionId: $request->execution->getKey(),
                correlationId: $correlationId,
                businessObjective: $request->businessObjective,
                failure: $error,
                metadata: [
                    'failure_reason' => $exception->getMessage(),
                ],
            );
        }
    }

    private function assertExecutionScope(ExpertInvocationRequest $request): void
    {
        $execution = $request->execution;
        $assignment = $request->assignment;

        if ($execution->agent_assignment_id !== $assignment->getKey()) {
            throw new AuthorizationException('Expert invocation execution does not belong to the Agent assignment.');
        }

        if ($execution->organization_id !== $assignment->organization_id) {
            throw new AuthorizationException('Expert invocation crosses organization boundaries.');
        }

        if ($execution->enterprise_id !== $assignment->enterprise_id) {
            throw new AuthorizationException('Expert invocation crosses Enterprise boundaries.');
        }

        if ($request->correlationId !== null && $execution->correlation_id !== $request->correlationId) {
            throw new AuthorizationException('Expert invocation correlation does not match the parent Agent execution.');
        }

        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            throw new AuthorizationException('The Agent assignment is disabled.');
        }

        if (! $assignment->enterprise instanceof Enterprise) {
            throw new AuthorizationException('Expert invocation requires an Enterprise-scoped Agent assignment.');
        }
    }

    /**
     * @param  array<string, mixed>  $reasoningOutput
     * @return list<CapabilityRequest>
     */
    private function requestedCapabilities(array $reasoningOutput, ExpertInvocationRequest $request, Expert $runtime): array
    {
        $encoded = $reasoningOutput['capability_requests'] ?? [];

        if (! is_array($encoded)) {
            throw new AuthorizationException('The Expert returned an invalid Capability request collection.');
        }

        $requested = [];

        foreach ($encoded as $item) {
            if (is_string($item)) {
                $item = json_decode($item, true);
            }

            if (! is_array($item) || ! isset($item['capability']) || ! is_string($item['capability'])) {
                throw new AuthorizationException('The Expert returned an invalid Capability request.');
            }

            $capability = $item['capability'];
            $this->capabilities->resolve($capability);

            if (! in_array($capability, $runtime->capabilities(), true)) {
                throw new AuthorizationException(sprintf(
                    'Expert [%s] requested undeclared capability [%s].',
                    $request->expertSlug,
                    $capability,
                ));
            }

            $targetContext = is_array($item['target_context'] ?? null)
                ? $item['target_context']
                : $request->targetContext;
            $inputPayload = is_array($item['input_payload'] ?? null)
                ? $item['input_payload']
                : [];
            $approval = isset($item['approval_request_id'])
                ? \App\Models\ApprovalRequest::query()->find((int) $item['approval_request_id'])
                : null;
            $capabilityRequest = new CapabilityRequest(
                capability: $capability,
                assignment: $request->assignment,
                execution: $request->execution,
                actor: $request->actor,
                targetContext: $targetContext,
                inputPayload: $inputPayload,
                expertSlug: $request->expertSlug,
                approval: $approval,
                correlationId: isset($item['correlation_id']) && is_string($item['correlation_id'])
                    ? $item['correlation_id']
                    : $request->correlationId,
                idempotencyKey: isset($item['idempotency_key']) && is_string($item['idempotency_key'])
                    ? $item['idempotency_key']
                    : null,
            );

            if (! $this->capabilityAuthorizer->allowsRequest($capabilityRequest)) {
                throw new AuthorizationException(sprintf(
                    'The Agent is not authorized to request capability [%s] through Expert [%s].',
                    $capability,
                    $request->expertSlug,
                ));
            }

            $requested[] = $capabilityRequest;
        }

        return $requested;
    }

    /** @return list<array<string, mixed>> */
    private function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }
}