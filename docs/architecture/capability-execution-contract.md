# CR8OR Canonical Capability Execution Contract

Issue #379 establishes the common execution contract for four business entry points:

1. Agent
2. deterministic Workflow
3. direct MCP business Tool
4. authenticated command Webhook

All four converge on:

Agent / Workflow / MCP / Command Webhook
→ CapabilityInvocationRequest
→ CapabilityInvocationService
→ CapabilityRegistry
→ Operation

## Canonical request metadata

| Field | Agent | Workflow | Direct MCP | Command Webhook |
|---|---|---|---|---|
| actor | authenticated execution actor | workflow actor | authenticated MCP user | credential-bound user |
| Enterprise | Agent assignment Enterprise | Workflow Enterprise | resolved MCP Enterprise | EnterpriseIdentityResolver |
| Capability | Agent request | persisted WorkflowStage | registry-bound Tool | route + credential allowlist |
| Operation | registry only | registry only | registry only | registry only |
| Expert | required | persisted stage Expert | optional MCP provenance | not used |
| Agent assignment | required | not used | optional | not used |
| Agent execution | required | not used | optional | not used |
| Workflow execution/stage | not used | required | not used | not used |
| Approval | AgentCapabilityAuthorizer | Workflow continuation state | Tool-specific governed authorization where required | approval-required Capabilities are rejected without an Agent-bound approval context |
| correlation ID | execution/request | WorkflowExecution | request or execution | payload/header, required |
| idempotency key | request/execution | version + stage execution key | Tool request | required and persisted in delivery ledger |
| target context | request | stage context + mappings | Tool-resolved target context | validated request target context |
| failure contract | CapabilityInvocationService + Agent failure handling | CapabilityInvocationService + Workflow persistence | CapabilityInvocationService + MCP failure responder | CapabilityInvocationService + HTTP failure contract |
| audit/provenance | Agent execution events | Workflow execution persistence | synchronous provenance | persisted command delivery + provenance |

## Authorization

CapabilityInvocationService is the final business execution boundary.

Entry-point-specific authorization remains where it expresses legitimate context:

- Agent: AgentAssignment + Expert + AgentCapabilityAuthorizer.
- Workflow: published WorkflowStage + declared Expert + declared Capability + Enterprise scope.
- Direct MCP: MCP authentication and Tool-specific target/approval checks, followed by CapabilityInvocationService.
- Command Webhook: HMAC-authenticated credential, configured Capability allowlist, EnterpriseIdentityResolver, then CapabilityInvocationService.

A command Webhook never accepts an Operation class, service class, or arbitrary model mutation instruction.

## Approval

Approval requirements remain Capability metadata.

- Agent and Workflow execution can enter the existing approval state machine.
- Direct MCP Tools that require approval retain their explicit server-side approval checks before invoking the shared boundary.
- Command Webhooks do not create a parallel approval system. Capabilities configured as approval-required are rejected unless an Agent-bound approval context exists.

This is deliberate. An authenticated Webhook credential is not an implicit approval grant.

## Idempotency

Idempotency is not implemented as a second generic execution engine.

- Agent execution keeps its existing durable execution/step idempotency.
- Workflow execution keeps published-version/stage idempotency.
- Direct MCP Tools pass idempotency through the common request where supplied.
- Command Webhooks persist a unique credential + idempotency key delivery record and return the original result for duplicates.

Operations retain responsibility for domain-specific idempotency where the domain requires it.

## Failure contract

CapabilityInvocationService translates Operation failures through the existing provider-neutral FailureTranslator and CapabilityExecutionException.

Command Webhooks serialize that same ExecutionError contract under an HTTP response envelope. Integration-result Webhooks remain a separate reconciliation model and are not routed through CapabilityInvocationService.

## Regression coverage

The convergence is covered by existing and phase-specific tests:

- Agent ↔ direct MCP business effect equivalence: CapabilityToolsTest.
- Workflow ↔ direct MCP business effect equivalence: WorkflowDeterministicExecutionTest.
- Command Webhook authentication, allowlisting, Enterprise resolution, idempotency, correlation, approval rejection and canonical failures: CommandWebhookTest.
- Capability boundary and lifecycle namespace: CapabilityRegistryTest.
- Workflow publication/runtime invariants: WorkflowDefinitionValidationTest and WorkflowContinuationTest.

No ModelProvider is required for any deterministic convergence test.