# CR8OR MCP Architecture

MCP is the controlled AI-facing interface to CR8OR capabilities. It exposes authorized context and operations without becoming a duplicate application or domain layer.

## Canonical Capability → Operation → Tool naming

CR8OR deliberately separates the business authority, executable operation and MCP interface:

| Concept | Naming convention | Example |
|---|---|---|
| Capability | hierarchical dot notation | `marketing.content.create` |
| Operation | PascalCase | `CreateContentItem` |
| Tool | kebab-case | `create-content-item` |

A Tool does not grant authority. A Capability declaration does not grant permission. Authorization is evaluated server-side before the Operation executes.

## Resources vs Tools

Resources provide authorized contextual information to an AI client and should represent useful business context rather than raw database structure.

Tools request explicit capabilities that may change state or initiate execution.

### Governed tool catalogue

The state-changing catalogue is intentionally limited to implemented, server-authorized capabilities:

| Tool | Capability | Operation | Purpose |
| --- | --- | --- |
| create-enterprise | enterprise.create | CreateEnterprise | Create an Enterprise under an organization where the authenticated actor has Enterprise creation authority. |
| create-enterprise-context | enterprise.context.create | CreateEnterpriseContext | Create business context for an authorized Enterprise; each Enterprise has one context record. |
| create-work-item | work.item.create | CreateWorkItem | Create a work item under an authorized enterprise. |
| update-work-item | work.item.update | UpdateWorkItem | Update an existing work item without changing enterprise ownership. |
| create-strategy | strategy.create | CreateStrategy | Create a strategy under an authorized objective. |
| update-strategy | strategy.update | UpdateStrategy | Update an existing strategy without changing objective ownership. |
| create-content-item | marketing.content.create | CreateContentItem | Create draft Content Item state under an authorized enterprise. |
| update-content-item | marketing.content.update | UpdateContentItem | Revise draft or in-review Content Item state. |
| submit-content-for-review | marketing.content.review | SubmitContentForReview | Move draft content into the governed review state. |
| mark-content-publication-ready | marketing.content.publication-ready | MarkContentPublicationReady | Mark approved content publication-ready only with matching server-side approval. |
| request-approval | approval.request | ApprovalRequestCreate | Create an auditable approval request for an Agent capability and exact target context. |
| delegate-agent | `agent.delegate` | DelegateAgent | Delegate governed work between same-Enterprise Agent assignments through `AgentDelegationService`. |
| analyze-business-context | `business.analysis` | AnalyzeBusinessContext | Run Business Analysis Expert methodology against authorized Enterprise, Strategy, Work and Financial context. |
| plan-marketing | `marketing.plan` | PlanMarketing | Run Marketing Expert planning methodology against authorized Enterprise, Strategy and Knowledge context. |
| generate-financial-report | `finance.report.generate` | GenerateFinancialReport | Generate a historical, Enterprise-scoped financial report through `FinancialReportingService`; no generic financial CRUD is exposed. |

For mutation tools, a human MCP call uses the existing Laravel policy for the target resource. An Agent-backed call must provide both agent_assignment_id and agent_execution_id; CR8OR resolves the MCP Tool through `CapabilityRegistry` first, then verifies that the execution belongs to the authenticated actor, assignment and enterprise before calling AgentCapabilityAuthorizer. Every state-changing MCP Tool has exactly one explicit Capability and one Operation. Generic mutation/transition fallbacks are not registered and cannot grant Agent authority; governed Tools must resolve directly through their explicit CapabilityRegistry definitions.

If the Agent permission requires approval, the mutation must also supply a valid approval_request_id whose organization, enterprise, assignment, execution, actor, capability and normalized target context match the current operation.

### Agent and Expert capability boundary

The current runtime capability graph is derived from the PHP Agent/Expert classes and the governed MCP registry rather than a persistent Capability model. Governed mutation Tools resolve through `CapabilityRegistry` to exactly one Capability and Operation before execution; specialized analysis, planning, delegation and Finance Tools retain their existing application-service boundaries.

Agent-backed analysis, planning and Finance calls must provide both `agent_assignment_id` and `agent_execution_id`; CR8OR verifies the execution, actor, assignment, Enterprise and declared capability before the application service runs. Human calls use the existing Enterprise/model policy boundary. MCP tool registration does not itself grant authority.

## Authentication Boundary

The remote MCP entry point is registered at /mcp through Laravel MCP and protected by Laravel Passport's auth:api guard. Laravel MCP's OAuth discovery and dynamic client-registration routes are registered through Mcp::oauthRoutes(). Passport provides the OAuth identity layer; CR8OR authorization remains a separate application concern.

## Authorization Boundary

Authorization is enforced by CR8OR server-side for the relevant actor, organization and capability/resource. MCP visibility is never the security boundary.

Capability authorization has three independent dimensions:

- **Availability:** the Agent or Expert runtime declares the Capability it can use. This is runtime metadata, not authority.
- **Permission:** the Agent assignment has an explicit server-side permission for the Capability in the applicable organization and Enterprise scope.
- **Approval:** a separate approval decision is required when that permission is configured to require approval. Approval never follows merely from Capability availability or permission.

An Expert-owned Capability does not require duplicate Agent permission. An Agent that is not authorized to use the Expert is denied, and an Expert cannot request a Capability it does not declare. Multiple Experts may reuse a Capability only where the registry explicitly permits that ownership model.

## Invocation Boundary

MCP Tool request → authentication → Capability authorization → validation → Operation → application/domain service → persistence/events/jobs → result

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

Tool failures are returned as MCP `isError` responses using the canonical provider-neutral CR8OR failure contract documented in `docs/failures.md`. The payload contains `type`, `code`, `message`, `retryable`, `correlation_id`, `diagnostic_id`, optional `operation`/`capability`/`tool` provenance, and safe structured `details`. Validation failures may contain field-level details. MCP serializes the application failure and must not invent transport-specific failure semantics.

Server-side logs contain correlation, actor, organization/execution and failure metadata only. Secrets, credentials, tokens and model prompt/context are not logged.

An AgentExecution records its correlation identifier, provider and external provider invocation identifier when available. Provider failure records the normalized failure code and transitions the execution to `failed`; a provider failure cannot produce a successful AgentDecision.

### Financial context

Phase 6.5 financial context is exposed through the existing authorization-aware Agent application context assembler rather than as a separate MCP financial resource. It is Enterprise-scoped, authorization-checked before assembly, and exposes derived/intentional context without unrestricted financial record access. A dedicated financial MCP resource is deferred unless a later product requirement establishes a distinct MCP contract.

## Agent runtime context and memory

The canonical Agent context pipeline may include the explicit `memory` context requirement. Memory is not exposed as a standalone MCP mutation surface or independent source of authority. When an Agent execution requests memory context, CR8OR resolves it through `AgentContextBuilder` and `MemoryContextProvider`, which enforce the current Agent Enterprise assignment, organization/Enterprise scope, bounded retrieval and provenance metadata. Episodic and semantic memory remain derived Agent runtime records and do not replace Knowledge, business state or execution history.

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


## Domain and lifecycle operation surface

The governed mutation surface now covers the core planning, content, work, and integration context needed by Agents:

| Domain | Tools / Operations |
| --- | --- |
| Organization / Enterprise context | `create-enterprise`, `create-enterprise-context` |
| Planning | `create-objective`, `update-objective`, existing `create-strategy`, `update-strategy` |
| Campaigns | `create-campaign`, `update-campaign`, `transition-campaign` |
| Content series | `create-content-series`, `update-content-series`, `transition-content-series` |
| Audiences | `create-audience`, `update-audience`, `archive-audience` |
| Channels | `create-channel`, `update-channel`, `archive-channel` |
| Social accounts | `list-social-accounts`, `get-social-account`, `connect-social-account`, `update-social-account`, `disconnect-social-account` |
| Projects | `list-projects`, `get-project`, `create-project`, `update-project` |
| Existing content lifecycle | `create-content-item`, `update-content-item`, `submit-content-for-review`, `mark-content-publication-ready`, `publish-content`, `request-approval` |

Campaign creation is intentionally bound to the existing `MarketingStrategy` relationship in the current domain model. Marketing strategies are therefore discoverable through `list-marketing-strategies` and `get-marketing-strategy`; the MCP layer does not invent a second strategy persistence model.

Project and objective relationships remain discoverable through `list/get` Tools for goals, KPIs, plans, and initiatives. Cross-enterprise relationship references are rejected in the domain service before persistence.

Social-account Tools expose provider metadata and external identifiers only. They never accept, return, log, or mutate raw OAuth tokens or credentials. `connect-social-account` registers an already-authorized account context; provider-specific OAuth/token exchange remains an integration concern.

Lifecycle Operations use the domain model transition methods where those transitions exist. They do not permit arbitrary status writes to bypass the model's transition rules. Human approval remains a separate governed step; MCP mutation authority does not imply approval authority.

All state-changing Operations continue through the existing authorization boundary and application/domain services. The MCP layer remains a transport and validation boundary rather than a second business-rule implementation.

## Knowledge Retrieval Boundary

Knowledge retrieval is an application-service capability rather than direct MCP persistence or database access. The canonical KnowledgeRetrievalService enforces Enterprise authorization before invoking a provider and provenance normalization after provider execution. Lexical, semantic and hybrid retrieval share the same provider-neutral contract.

MCP resources and future retrieval tools must use this boundary rather than querying KnowledgeIndexUnit, KnowledgeEmbedding or authoritative Knowledge records directly. Retrieved Knowledge remains bounded and provenance-aware when consumed by the Agent context pipeline.
## Knowledge resource and retrieval surface

Phase 12 adds a dedicated Knowledge resource surface while preserving the existing authoritative/derived boundary.

| Domain | Tool | Canonical Operation | Authority |
| --- | --- | --- | --- |
| Knowledge index | create-knowledge-index | CreateKnowledgeIndex | Resource operation; rebuilds derived index state from authoritative Knowledge |
| Knowledge index | get-knowledge-index | GetKnowledgeIndex | Authorized resource query |
| Knowledge index | list-knowledge-indexes | ListKnowledgeIndexes | Authorized bounded resource query |
| Knowledge index | update-knowledge-index | UpdateKnowledgeIndex | Resource operation; reindexes authoritative Knowledge |
| Knowledge unit | create-knowledge-unit | CreateKnowledgeUnit | Resource operation; materializes a derived unit from authoritative Knowledge |
| Knowledge unit | get-knowledge-unit | GetKnowledgeUnit | Authorized resource query |
| Knowledge unit | list-knowledge-units | ListKnowledgeUnits | Authorized bounded resource query |
| Knowledge unit | update-knowledge-unit | UpdateKnowledgeUnit | Resource operation; rebuilds the unit from authoritative Knowledge |
| Knowledge unit | archive-knowledge-unit | ArchiveKnowledgeUnit | Resource operation; archives the derived representation without deleting authoritative Knowledge |
| Retrieval | retrieve-knowledge | RetrieveKnowledge | Governed knowledge.retrieve capability |

Knowledge Index Records and Knowledge Index Units are derived search representations. Their content is never authoritative business state and MCP resource operations do not permit arbitrary persistence writes to those records. Create/update operations rebuild them through KnowledgeIndexingService; archive marks the derived representation removed while retaining historical state.

retrieve-knowledge supports lexical, semantic, and hybrid modes, bounded results (1-50), optional minimum relevance, correlation identifiers, Enterprise authorization and provenance normalization. Agent-backed retrieval requires both agent_assignment_id and agent_execution_id and is authorized through McpCapabilityAuthorizer.

Cross-Enterprise access is rejected at the application boundary. MCP handlers do not query or mutate Knowledge persistence directly.
## Memory resource and retrieval surface

Phase 12 exposes the existing durable Agent Memory stores through a single enterprise-scoped MCP resource contract while keeping episodic and semantic lifecycle rules distinct.

| Domain | Tool | Canonical Operation | Authority |
| --- | --- | --- | --- |
| Memory | create-memory | CreateMemory | Resource operation; requires explicit source execution provenance |
| Memory | get-memory | GetMemory | Authorized resource query |
| Memory | list-memory | ListMemory | Authorized bounded resource query/search |
| Memory | update-memory | UpdateMemory | Resource operation; semantic Memory only, with version history |
| Memory | archive-memory | ArchiveMemory | Resource operation; semantic Memory lifecycle |
| Memory | retrieve-memory | RetrieveMemory | Governed memory.retrieve capability |
| Memory | record-memory | RecordMemory | Governed memory.record capability |

Episodic Memory remains an immutable concise record of a meaningful Agent execution event. Semantic Memory supports explicit versioned updates and archival. Durable writes require explicit source execution provenance; transient Agent context is never silently persisted as Memory.

memory.retrieve is bounded and Enterprise/Agent scoped. memory.record requires an explicit persist=true request and uses the same application/domain Memory services as resource creation. Neither capability exposes hidden chain-of-thought.

All Memory MCP handlers are adapters over Operations and application services. They do not mutate Eloquent models directly.
## Agent Assignment resource and lifecycle

Agent Assignments expose their existing durable lifecycle through MCP as resource operations. The surface is Enterprise-scoped and uses the existing `AgentAssignmentPolicy` and `AgentAssignmentService`; MCP registration does not itself grant management authority.

| Tool | Operation | Purpose |
| --- | --- | --- |
| create-agent-assignment | CreateAgentAssignment | Create an idempotent Enterprise-scoped assignment with objective, requirements and context |
| get-agent-assignment | GetAgentAssignment | Read one assignment within Enterprise scope |
| list-agent-assignments | ListAgentAssignments | Bounded listing with Agent and lifecycle filters |
| update-agent-assignment | UpdateAgentAssignment | Update assignment definition while preserving lifecycle state rules |
| transition-agent-assignment | TransitionAgentAssignment | Apply only explicit state-machine transitions |

Lifecycle transitions are `draft -> ready -> running -> paused -> running` plus terminal `completed`, `failed`, and `cancelled` paths as defined by the domain model. Starting an assignment records `started_at`; terminal transitions record `completed_at`; completed and cancelled assignments are disabled.

Management operations require the existing assignment management policy. Cross-Enterprise access is rejected before the resource is returned. The MCP tools remain thin adapters over Operations and the existing Assignment service.


## Agent Execution resource and runtime surface

Agent Executions are durable runtime resources linked to an Enterprise, Agent Assignment and Agent. The MCP surface uses the existing `AgentExecutionService` and queue/job runtime; MCP does not implement a second execution engine.

| Tool | Canonical action | Purpose |
| --- | --- | --- |
| `execute-agent` | `agent.execute` / `ExecuteAgent` | Governed start of an Agent Execution |
| `create-agent-execution` | `CreateAgentExecution` | Resource-level durable execution start |
| `get-execution` | `GetAgentExecution` | Retrieve durable status, input, result and runtime history |
| `list-executions` | `ListAgentExecutions` | Bounded Enterprise-scoped execution listing |
| `resume-execution` | `ResumeAgentExecution` | Resume waiting/paused/delegated execution through the canonical runtime |
| `cancel-execution` | `CancelAgentExecution` | Cancel an active execution through its lifecycle rules |

Execution starts are idempotent by organization and idempotency key. Starts through the resource surface require the Assignment to be `ready` or `running`; invalid Assignment state is rejected before dispatch. Execution failures remain durable with structured failure code/category, while structured results are persisted without hidden chain-of-thought.

All execution starts require an explicit `mode`: `interactive` or `autonomous`. `interactive` requests may include `capability_requests`; they execute through the canonical governed Capability path and do not invoke a ModelProvider. `autonomous` follows the existing queued Agent reasoning loop. Resume preserves the persisted mode and may supply additional interactive Capability Requests when the execution is waiting for approval/input.

## Complete MCP surface contract

The CR8OR server registers every concrete MCP Tool class under `app/Mcp/Tools`; a contract test fails if a concrete tool is added without server registration. Governed action tools resolve through `CapabilityRegistry`, while resource/discovery tools resolve to canonical resource Operations or query services. The server also exposes the Enterprise, Strategy, Knowledge and Work context resources through URI templates.

The live surface is intentionally split into: discovery/read resources, domain resource CRUD and lifecycle tools, governed capability tools, and Agent runtime tools. This keeps direct MCP clients on the same authorization, approval, lifecycle and provenance paths as internal execution.


## Runtime E2E audit

`E2E-TEST-20260928` is the canonical Phase 12 runtime audit. It exercises the live MCP contract rather than application services directly: Enterprise and Enterprise Context creation, Knowledge indexing/retrieval, Memory creation/retrieval, Agent Assignment and Execution resources, Agent-to-Agent delegation, Expert-backed child execution, governed Content creation, and human review.

The audit also verifies duplicate creation, invalid relationships, missing execution input, invalid lifecycle transitions, and cross-Enterprise access. It writes a machine-readable report to `storage/app/e2e/E2E-TEST-20260928.json` and prints the complete report to the test output. The test database is isolated by the repository Feature-test `RefreshDatabase` harness.

Delegation inspection is exposed through `get-agent-delegation` and `list-agent-delegations`, allowing direct MCP clients to reconstruct the parent execution → delegation → child execution relationship without database access. Delegation may explicitly provide registered Expert slugs for the child execution; the canonical `AgentDelegationService` passes those slugs into the Agent execution path.

### Authoritative Knowledge creation

`create-knowledge-item` is the canonical MCP resource operation for creating an Enterprise-scoped authoritative Knowledge Item and its initial immutable Knowledge Version. It exists so a direct MCP client can construct the Knowledge branch before invoking the derived Knowledge Index/Unit lifecycle; indexing remains a derived representation and never becomes the authoritative content store.
## Enterprise Context Capability coverage

Enterprise Context is available through both `enterprise.context.create` and `enterprise.context.retrieve`. The retrieval Capability resolves to `EnterpriseContextRetrieve`, which is also the application Operation consumed by the Enterprise Context MCP resource. The application contract returns the canonical Enterprise/Context representation used by downstream Agent and Expert composition; the MCP resource retains its existing transport response shape and does not expose persistence metadata.
## Knowledge Capability boundary

The state-changing Knowledge MCP adapters `create-knowledge-item`, `create-knowledge-index`, and `create-knowledge-unit` resolve through the Capability Registry and `CapabilityInvocationService`. They do not execute Knowledge Operations directly. The application-native Knowledge lifecycle is therefore:

**Capability → Operation → Knowledge service → Persistence / derived index**

`retrieve-knowledge` already uses the same governed boundary. MCP remains an adapter and is not required for Capability-native Knowledge execution.


## Capability-native E2E is separate from MCP

`E2E-CAPABILITY-20260928` is the application-path validation for Phase 12. It must not invoke MCP CRUD/action Tools. MCP remains a transport/interface adapter and is validated separately by the MCP E2E contract. The Capability E2E starts at `CapabilityInvocationService` and verifies persisted relationships and governance independently of the MCP surface.
### Exception translation boundary

MCP tools delegate exception-to-failure mapping to `App\\Services\\FailureTranslator`. MCP is a transport boundary only: it serializes the canonical failure contract and never invents a second exception taxonomy. Unknown exceptions become `internal.unexpected`; internal diagnostics remain server-side.

### Capability failure provenance

Governed MCP tools resolve their Capability → Operation mapping from CapabilityRegistry. Operation failures are translated through the canonical failure boundary with operation and capability provenance before MCP serialization. Authorization and approval failures remain distinct failure categories and are not collapsed into generic Operation failures.

### Diagnostic workflow

MCP responses expose the correlation ID, diagnostic ID and canonical failure code needed for support correlation. They do not expose exception classes, traces, credentials, provider payloads or model context. Operators use the correlation ID to locate the structured failure event and the diagnostic ID to locate the sanitized internal diagnostic record.