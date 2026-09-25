# CR8OR MCP Architecture

MCP is the controlled AI-facing interface to CR8OR capabilities. It exposes authorized context and operations without becoming a duplicate application or domain layer.

## Resources vs Tools

Resources provide authorized contextual information to an AI client and should represent useful business context rather than raw database structure.

Tools request explicit capabilities that may change state or initiate execution.

### Governed tool catalogue

The state-changing catalogue is intentionally limited to implemented, server-authorized capabilities:

| Tool | Capability | Purpose |
| --- | --- | --- |
| create-enterprise | enterprise.create | Create an Enterprise under an organization where the authenticated actor has Enterprise creation authority. |
| create-work-item | work.create | Create a work item under an authorized enterprise. |
| update-work-item | work.update | Update an existing work item without changing enterprise ownership. |
| create-strategy | strategy.create | Create a strategy under an authorized objective. |
| update-strategy | strategy.update | Update an existing strategy without changing objective ownership. |
| create-content-item | content.create | Create draft Content Item state under an authorized enterprise. |
| update-content-item | content.update | Revise draft or in-review Content Item state. |
| submit-content-for-review | content.review | Move draft content into the governed review state. |
| mark-content-publication-ready | content.publication_ready | Mark approved content publication-ready only with matching server-side approval. |
| request-approval | N/A | Create an auditable approval request for an Agent capability and exact target context. |
| delegate-agent | `agent.delegate` | Delegate governed work between same-Enterprise Agent assignments through `AgentDelegationService`. |
| analyze-business-context | `business.analysis` | Run Business Analysis Expert methodology against authorized Enterprise, Strategy, Work and Financial context. |
| plan-marketing | `marketing.plan` | Run Marketing Expert planning methodology against authorized Enterprise, Strategy and Knowledge context. |
| generate-financial-report | `finance.execute` | Generate a historical, Enterprise-scoped financial report through `FinancialReportingService`; no generic financial CRUD is exposed. |

For mutation tools, a human MCP call uses the existing Laravel policy for the target resource. An Agent-backed call must provide both agent_assignment_id and agent_execution_id; CR8OR verifies that the execution belongs to the authenticated actor, assignment and enterprise before calling AgentCapabilityAuthorizer.

If the Agent permission requires approval, the mutation must also supply a valid approval_request_id whose organization, enterprise, assignment, execution, actor, capability and normalized target context match the current operation.

### Agent and Expert action boundary

The current runtime capability graph is derived from the PHP Agent/Expert classes rather than a persistent Capability model. `delegate-agent` reuses `AgentDelegationService` and its existing approval, organization/Enterprise, correlation and idempotency controls. `analyze-business-context` and `plan-marketing` use `ExpertCapabilityService` to validate the enabled Expert runtime, assemble only the Expert's declared context and invoke its methodology. `generate-financial-report` uses `FinancialReportingService` and the existing FinancialReport policy boundary.

Agent-backed analysis, planning and Finance calls must provide both `agent_assignment_id` and `agent_execution_id`; CR8OR verifies the execution, actor, assignment, Enterprise and declared capability before the application service runs. Human calls use the existing Enterprise/model policy boundary. MCP tool registration does not itself grant authority.

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

Future resources and tools should use stable, capability-oriented business names rather than internal table names. The current Phase 4 catalogue is intentionally limited to verified Phase 1-3 capabilities; later product phases may add capabilities without weakening the same authorization boundary.

Phase 4.1 implements the protected MCP transport and authentication boundary. Phase 4.2 adds authorized contextual resources. Phase 4.3 adds the initial governed capability tools. Phase 4.4 adds the provider-neutral model adapter. Phase 4.5 adds governed Agent/Expert execution. Phase 4.6 adds operational audit, error and correlation contracts. Phase 4.7 verifies the complete Phase 4 implementation and reconciles documentation.

## Error and correlation contract

Every MCP tool execution receives a correlation identifier from `X-Correlation-ID` when supplied, or a generated UUID otherwise. MCP clients may also provide `cr8or.correlation_id` in request metadata. The identifier is returned in MCP error payloads and the HTTP response header and is propagated to AgentExecution and ApprovalRequest records where those records are created.

Tool failures are returned as MCP `isError` responses with a machine-readable error object containing `type`, `code`, `message`, `retryable`, `correlation_id` and, for validation failures, field-level details. Failure classes are authentication, authorization, validation, unavailable-resource, business-rule, provider, external-execution and internal.

Server-side logs contain correlation, actor, organization/execution and failure metadata only. Secrets, credentials, tokens and model prompt/context are not logged.

An AgentExecution records its correlation identifier, provider and external provider invocation identifier when available. Provider failure records the normalized failure code and transitions the execution to `failed`; a provider failure cannot produce a successful AgentDecision.

### Financial context

Phase 6.5 financial context is exposed through the existing authorization-aware Agent application context assembler rather than as a separate MCP financial resource. It is Enterprise-scoped, authorization-checked before assembly, and exposes derived/intentional context without unrestricted financial record access. A dedicated financial MCP resource is deferred unless a later product requirement establishes a distinct MCP contract.

## Discovery tools

The foundational read/discovery layer exposes bounded, authorization-aware list/get tools for:

| Domain | Tools |
| --- | --- |
| Organization | `list-enterprises`, `get-enterprise` |
| Strategy | `list-objectives`, `get-objective`, `list-strategies`, `get-strategy` |
| Work | `list-work-items`, `get-work-item` |
| Intelligence | `list-agents`, `get-agent`, `list-experts`, `get-expert`, `list-capabilities`, `get-capability` |
| Content | `list-campaigns`, `get-campaign`, `list-content-series`, `get-content-series`, `list-content-items`, `get-content-item`, `list-audiences`, `get-audience`, `list-channels`, `get-channel` |
| Operations | `list-executions`, `get-execution`, `list-approval-requests`, `get-approval-request` |

List tools return `result.items` plus a bounded `pagination` object containing `page`, `per_page`, `total`, and `last_page`. They accept `per_page` (1-50), `page`, and, where applicable, enterprise, status, parent-resource, and name/title/slug search filters. All list queries are scoped to organizations accessible to the authenticated actor before pagination.

Get tools return a single `result` object and first resolve the requested record inside the actor's authorized organization/enterprise scope, followed by the resource policy's `view` authorization.

Agent and Expert descriptors use their existing policy boundary. Capability discovery is runtime-derived rather than backed by a persistent Capability model: a capability identifier is the stable capability string exposed by enabled Agent/Expert runtimes, and `get-capability` accepts that string as its `id`. This avoids inventing a second persistent source of truth for runtime capability metadata.

Discovery responses expose stable identifiers, human-readable names/titles where the underlying resource has them, relevant status and parent identifiers, and timestamps. They do not return raw Eloquent models or persistence internals.

A typical discovery-first workflow is:

1. `list-enterprises`
2. `list-objectives` with the enterprise scope
3. `create-strategy` using the discovered objective id
4. `get-strategy` using the resulting strategy id
5. Discover related work, content, execution, or approval records as required.

Discovery tools use the same Laravel policy and organization/enterprise authorization boundaries as the application. They do not grant mutation authority and do not bypass application services or policies.


## Domain and lifecycle action surface

The governed mutation surface now covers the core planning, content, work, and integration context needed by Agents:

| Domain | Actions |
| --- | --- |
| Organization | `create-enterprise` |
| Planning | `create-objective`, `update-objective`, existing `create-strategy`, `update-strategy` |
| Campaigns | `create-campaign`, `update-campaign`, `transition-campaign` |
| Content series | `create-content-series`, `update-content-series`, `transition-content-series` |
| Audiences | `create-audience`, `update-audience`, `archive-audience` |
| Channels | `create-channel`, `update-channel`, `archive-channel` |
| Social accounts | `list-social-accounts`, `get-social-account`, `connect-social-account`, `update-social-account`, `disconnect-social-account` |
| Projects | `list-projects`, `get-project`, `create-project`, `update-project` |
| Existing content lifecycle | `create-content-item`, `update-content-item`, `submit-content-for-review`, `mark-content-publication-ready`, `publish-content`, `request-approval` |

Campaign creation is intentionally bound to the existing `MarketingStrategy` relationship in the current domain model. Marketing strategies are therefore discoverable through `list-marketing-strategies` and `get-marketing-strategy`; the MCP layer does not invent a second strategy persistence model.

Project and objective relationships remain discoverable through `list/get` actions for goals, KPIs, plans, and initiatives. Cross-enterprise relationship references are rejected in the domain service before persistence.

Social-account actions expose provider metadata and external identifiers only. They never accept, return, log, or mutate raw OAuth tokens or credentials. `connect-social-account` registers an already-authorized account context; provider-specific OAuth/token exchange remains an integration concern.

Lifecycle actions use the domain model transition methods where those transitions exist. They do not permit arbitrary status writes to bypass the model's transition rules. Human approval remains a separate governed step; MCP mutation authority does not imply approval authority.

All state-changing actions continue through the existing authorization boundary and application/domain services. The MCP layer remains a transport and validation boundary rather than a second business-rule implementation.
