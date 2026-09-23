# CR8OR MCP Architecture

MCP is the controlled AI-facing interface to CR8OR capabilities. It exposes authorized context and operations without becoming a duplicate application or domain layer.

## Resources vs Tools

Resources provide authorized contextual information to an AI client and should represent useful business context rather than raw database structure.

Tools request explicit capabilities that may change state or initiate execution.

### Phase 4.3 tool catalogue

The initial state-changing catalogue is intentionally small and limited to already implemented Phase 1-3 capabilities:

| Tool | Capability | Purpose |
| --- | --- | --- |
| create-work-item | work.create | Create a work item under an authorized enterprise. |
| update-work-item | work.update | Update an existing work item without changing enterprise ownership. |
| create-strategy | strategy.create | Create a strategy under an authorized objective. |
| update-strategy | strategy.update | Update an existing strategy without changing objective ownership. |
| request-approval | N/A | Create an auditable approval request for an Agent capability and exact target context. |

For mutation tools, a human MCP call uses the existing Laravel policy for the target resource. An Agent-backed call must provide both agent_assignment_id and agent_execution_id; CR8OR verifies that the execution belongs to the authenticated actor, assignment and enterprise before calling AgentCapabilityAuthorizer.

If the Agent permission requires approval, the mutation must also supply a valid approval_request_id whose organization, enterprise, assignment, execution, actor, capability and normalized target context match the current operation.

## Authentication Boundary

The remote MCP entry point is registered at /mcp through Laravel MCP and protected by Laravel Passport's auth:api guard. Laravel MCP's OAuth discovery and dynamic client-registration routes are registered through Mcp::oauthRoutes(). Passport provides the OAuth identity layer; CR8OR authorization remains a separate application concern.

## Authorization Boundary

Authorization is enforced by CR8OR server-side for the relevant actor, organization and capability/resource. MCP visibility is never the security boundary.

## Invocation Boundary

MCP request → authentication → authorization → validation → application/domain service → persistence/events/jobs → result

MCP handlers must not implement business rules that belong in application/domain services.

## Validation and Errors

MCP requests validate required input before invoking application services. Errors distinguish validation, authorization, unavailable-resource and application failures through Laravel MCP's error responses. A failed state-changing operation is never represented as successful merely because the transport succeeded.

## Auditability

State-changing MCP operations are attributable to the authenticated actor and, for Agent-backed operations, the Agent assignment, execution, capability and approval context.

## Direct Persistence Prohibition

MCP must not directly mutate Eloquent models, perform arbitrary database writes, or encode business invariants that belong in the application/domain service layer.

## Naming and Scope

Future resources and tools should use stable, capability-oriented business names rather than internal table names. No additional naming catalogue is established until concrete MCP implementation begins.

The Phase 4.1 foundation implements the protected MCP transport and authentication boundary. Phase 4.2 adds authorized contextual resources. Phase 4.3 adds the initial governed capability tools. Later Phase 4 issues add provider integration, Agent/Expert execution and operational audit contracts.

## Error and correlation contract

Every MCP tool execution receives a correlation identifier from `X-Correlation-ID` when supplied, or a generated UUID otherwise. MCP clients may also provide `cr8or.correlation_id` in request metadata. The identifier is returned in MCP error payloads and the HTTP response header and is propagated to AgentExecution and ApprovalRequest records where those records are created.

Tool failures are returned as MCP `isError` responses with a machine-readable error object containing `type`, `code`, `message`, `retryable`, `correlation_id` and, for validation failures, field-level details. Failure classes are authentication, authorization, validation, unavailable-resource, business-rule, provider, external-execution and internal.

Server-side logs contain correlation, actor, organization/execution and failure metadata only. Secrets, credentials, tokens and model prompt/context are not logged.

An AgentExecution records its correlation identifier, provider and external provider invocation identifier when available. Provider failure records the normalized failure code and transitions the execution to `failed`; a provider failure cannot produce a successful AgentDecision.
