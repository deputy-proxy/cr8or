# CR8OR Architecture

## Purpose

This document defines the implementation-facing architecture of CR8OR Core. It establishes ownership boundaries between the Laravel application, AI agents, MCP, orchestration, external execution services, and persistence.

## Architectural Model

**Agents reason and request governed capabilities → CR8OR owns state, rules and authorization → MCP exposes capabilities → external services execute.**

Automation platforms such as n8n are optional future MCP-connected capabilities that an Automatiser Expert may use; n8n is not CR8OR's primary orchestration layer.

| Boundary | Owns | Must not own |
|---|---|---|
| AI agents | Reasoning, planning, recommendations and decisions within assigned authority | Direct database access, hidden authorization, authoritative business state |
| Laravel / CR8OR | Business state, domain rules, application services, authorization, history | AI reasoning or specialized external execution |
| Filament | Administrative and operational interaction with CR8OR | Security decisions that are not enforced server-side |
| Application/domain services | Explicit business operations and invariants | AI transport concerns or duplicated MCP logic |
| Policies / authorization | Authority and data-access decisions | Business execution itself |
| Events | Meaningful state-change notifications | Ownership of business state |
| Jobs | Asynchronous CR8OR-owned work | Becoming an alternative business-state store |
| MCP | Controlled AI-facing capability interface | Direct model/database mutation or duplicated business logic |
| n8n | Optional future MCP-connected automation capability | Authoritative CR8OR business state or primary orchestration authority |
| External workers/services | Specialized execution | Authoritative CR8OR business state |
| R2 / storage | Canonical files and generated media | Business authorization or domain rules |

## Application Boundaries

CR8OR is a Laravel application. Laravel owns the authoritative operational record and the rules governing mutations.

Application services are the boundary for important operations. Domain logic must not be hidden inside controllers, MCP handlers, workflow nodes, or external services.

Filament is the administrative and operational interface. UI controls may reflect authorization, but server-side authorization remains mandatory.

Laravel jobs and events are used for asynchronous work that belongs to CR8OR. Work that coordinates multiple external services belongs at the orchestration boundary.

Workflow, Job and Execution records provide the authoritative trace for CR8OR-owned background work. A Workflow retains the originating Enterprise and optional operational work context, a Job represents one idempotent logical background operation, and an Execution records an attempt through an explicit pending/running/succeeded/failed lifecycle. This is execution tracking, not a general workflow engine. Concrete provider execution remains outside this boundary.

## AI and MCP Boundaries

AI agents reason over authorized CR8OR context. An agent may propose a plan or request a capability, but technical access to a tool does not itself grant business authority. Model calls cross an internal provider-neutral `App\AI\Contracts\ModelProvider` boundary. Laravel AI is currently the concrete infrastructure adapter, while fake providers keep runtime tests independent of network access and provider credentials.

Agent and Expert runtime components use canonical immutable definition objects for their authoritative runtime metadata. Agents expose AgentDefinition; Experts expose ExpertDefinition. These definitions describe identity, responsibilities, methodology/context and declared Capabilities but do not create authorization. The executable Agent/Expert classes remain the reasoning boundary, while application services remain responsible for governed execution.

MCP translates AI-facing requests into controlled CR8OR capabilities. MCP must authenticate the caller, establish authorization context, validate inputs, and invoke application/domain services. MCP is not a second domain layer.

## Orchestration and Execution Boundaries

n8n is an optional future automation capability that may be exposed through MCP and used by an Automatiser Expert. If connected, it may coordinate external steps, but CR8OR remains the source of truth for resulting business state.

Specialized services such as media renderers, publishing systems, Canva, GitHub, and AI providers perform bounded execution. Their responses are external execution results that CR8OR may persist or reconcile where they affect business state.

## Request Lifecycle

1. An authenticated user or AI client establishes identity.
2. An agent reasons over authorized context and forms an intent.
3. MCP or an application interface translates the intent into a named capability request.
4. Server-side authorization checks actor, organization, capability and target state.
5. An approval gate is applied when policy requires human approval.
6. An application/domain service validates and executes the business operation.
7. CR8OR persists the authoritative state transition.
8. Events and jobs communicate the resulting state change.
9. An external service performs specialized execution when required; an optional MCP-connected automation capability such as n8n may coordinate external steps.
10. The external result is correlated with the originating CR8OR operation.
11. CR8OR records relevant result, status and audit information.
12. Reporting and subsequent agent context derive from authoritative CR8OR state.

## Multi-Agent Reporting Boundary

`MultiAgentBusinessReportingService` is the application boundary for the Phase 7.5 business-level report. It authorizes the requested Enterprise through the existing Enterprise policy, reads only Enterprise-scoped authoritative records, and returns a deterministic derived view. It does not persist report state or mutate executions, delegations, approvals, workflows or domain results. Failed Agent executions, delegations and workflows remain explicitly represented as failures. Existing historical identity snapshots are used rather than reconstructing mutable Agent metadata.

The report is intentionally narrower than a generalized reporting system. It does not introduce a reporting engine, forecasting, analytics platform, visualization layer, route or MCP surface.

## Multi-Agent Administration Boundary

Phase 7.6 uses Filament as an operational interface over CR8OR-owned multi-Agent state. Delegation history and the collaboration report are organization-scoped through existing authorization/query-scoping patterns. Historical execution, decision, approval and delegation records are exposed read-only where required by their domain invariants. Runtime Agent/Expert metadata is resolved from PHP classes rather than editable descriptor copies. Filament does not become an alternative authorization or business-logic layer.

## Prohibited Shortcuts

- Agents must not connect directly to the database.
- MCP must not mutate Eloquent models as a substitute for application services.
- Controllers and UI components must not bypass authorization.
- n8n must not become a shadow database for CR8OR business state.
- External services must not silently redefine CR8OR business state.
- Domain invariants must not exist only in prompts or workflow nodes.
- External retries must not create duplicate business effects where idempotency is required.

This document establishes boundaries, not a final class hierarchy or database schema.

## Agent Execution Contract

Phase 8.3 establishes `App\Data\AgentExecutionRequest` as the canonical provider-neutral and transport-neutral input to `AgentExecutionService`. It carries the actor, Agent assignment, prompt, authorized target context, optional Expert selection, correlation identity and delegation context. Provider-specific options remain behind the application service boundary.

Capability requests produced during Agent or Expert reasoning are represented by `App\Data\CapabilityRequest`. The canonical contract carries the Capability identifier, target context, input payload, Agent assignment and execution identity, optional Expert identity, approval reference, correlation identity, idempotency key and delegated execution context. CR8OR resolves availability through the existing Capability registry and performs authorization before the existing Capability -> Operation -> application/domain service path. The contract itself never grants authority.

`AgentExecutionResult` remains the execution result boundary and `AgentExecution` remains the authoritative persistent lifecycle record. Normalized `ExecutionError` and `ExecutionCorrelationService` semantics continue to govern failure and correlation.

## Agent Context Contract

`App\Data\AgentContext` is the canonical provider-neutral contract for current Agent execution context. It separates context into named sections and carries source, organization/Enterprise scope and optional relevance metadata without exposing persistence models to the model provider. The canonical section order is Enterprise, Enterprise Context, Strategy, Work, Knowledge, Decisions, execution history and instructions.

`AgentContextBuilder` is the canonical composition boundary. It maintains an explicit provider registry for Enterprise, Strategy, Work, Knowledge, Decisions, execution history and Financial context, always establishes the Enterprise/Enterprise Context base, and then includes only the runtime-requested optional providers. Provider selection is deterministic and unsupported requirements fail explicitly.

Each context provider is an independent authorization boundary. Providers authorize the requested Enterprise or delegate to an authorization-aware assembler before returning serialized context. `AgentContextSection` carries source, organization/Enterprise scope and relevance metadata, providing bounded provenance/audit information without persisting complete model prompts or exposing Eloquent models to the model provider. `EnterpriseContextAssembler` remains the application boundary for Enterprise identity and Enterprise Context, including current Goals/KPIs, Products, Customers and Partners.

`McpContextAssembler` remains the compatibility/application facade for existing MCP resource methods and delegates Agent context composition to `AgentContextBuilder`. Agent execution and Expert capability execution consume the canonical contract and serialize only current context data at the runtime boundary.

`HistoricalContextAssembler` is the application boundary for bounded historical context. It reads Decision, EnterpriseDecision and AgentDecision records within the authorized Enterprise, plus relevant AgentExecution, AgentDelegation and ApprovalRequest history. Retrieval is bounded and uses persisted historical identity snapshots rather than reconstructing identity from current mutable Agent or Enterprise metadata. It does not mutate historical records or introduce an event store.

Persistent Agent memory is distinct from `AgentContext`, but it is now an explicit context source. Context represents the current authorized execution; memory is durable state with its own governance and is never treated as business authority. Phase 8.18 implements episodic memory as `AgentEpisodicMemory`, with explicit meaningful-event writes, Enterprise-scoped bounded retrieval and provenance back to the authoritative `AgentExecution`. Phase 8.19 adds semantic memory as `AgentSemanticMemory`, scoped to an Enterprise and Agent descriptor, with explicit confidence/status, source provenance, immutable version history and explicit conflict state. Phase 8.20 centralizes retrieval/write policy, Phase 8.21 exposes provenance and historical Agent identity, and Phase 8.22 integrates the bounded `MemoryContextProvider` into `AgentContextBuilder`. Memory is included only when explicitly requested by the Agent and remains a derived Agent-learned record that never replaces Knowledge or authoritative business records.

The Work context section is assembled by `WorkContextAssembler` after Enterprise authorization. It exposes bounded Projects, Tasks, Work Items, Milestones, relevant Assignments and Dependencies, plus bounded current Workflow/Job/Execution status. Task parent/child relationships and work references remain explicit, while execution state is presented as runtime status rather than business Work state. Historical Job/Execution logs are not exposed through this context boundary.

## Execution Error and Correlation Contract

MCP and Agent execution use a small shared error taxonomy rather than a generalized workflow/error engine. Correlation begins at the MCP HTTP/request boundary and is propagated to AgentExecution, ApprovalRequest and provider invocation metadata where applicable.

AgentExecution remains the authoritative lifecycle record for Agent execution. Its failure code is normalized independently from its human-readable failure reason, while provider and external invocation identifiers are stored only when actually returned.

The Phase 7 delegation boundary uses AgentDelegationService and explicit request/response data contracts. It persists an auditable AgentDelegation record with source/target assignment references, organization/Enterprise scope, historical identity snapshots, actor, parent AgentExecution, correlation and idempotency data. The service re-checks source delegation and target capability authority, preserves approval requirements, invokes the existing AgentExecutionService for the receiving Agent, and links the resulting AgentExecution back to the delegation. This is traceability for governed delegation, not a second workflow engine.

The existing Phase 3 Job/Execution idempotency model remains authoritative for background operations. MCP update tools marked idempotent rely on their existing replacement semantics; no second retry engine is introduced.
## Expert Invocation Contract

Phase 8.5 establishes `App\Data\ExpertInvocationRequest` and `App\Data\ExpertInvocationResult` as the canonical Agent-to-Expert runtime boundary. The request carries the actor, receiving Agent assignment, parent `AgentExecution`, runtime Agent, Expert identifier, business objective, authorized context, expected reasoning output, target context and correlation identity.

`ExpertInvocationService` resolves the Expert identity from the authoritative `ExpertDescriptor` registry and runtime class, verifies that the Expert is declared by the receiving Agent, verifies required context, and re-validates each declared Capability through the existing Agent assignment permission and approval boundary. It does not grant authority or execute Capabilities.

The result separates reasoning output, requested Capability Requests, decisions, recommendations, execution metadata and normalized failure state. Every successful result carries the parent `AgentExecution` identifier and correlation identity. Authorization failures preserve the existing authorization exception semantics; reasoning failures are represented as a failed invocation result so the parent Agent execution can record the failure.

## Knowledge Retrieval and AI Context Boundary

Phase 9 adds a governed retrieval layer beneath the canonical Agent context pipeline. KnowledgeRetrievalService is the application boundary: it authorizes the requested Enterprise before provider execution and re-validates returned Knowledge provenance afterward. Lexical, semantic and hybrid providers implement the same provider-neutral contract.

Search representations are derived state. KnowledgeIndexRecord owns representation lifecycle, KnowledgeIndexUnit owns normalized searchable content, and KnowledgeEmbedding owns semantic representation data. None is authoritative business Knowledge.

RetrievedKnowledgeContextProvider exposes retrieval to AgentContextBuilder only as the separate retrieved_knowledge section. Result limits and a deterministic serialized-size token estimate bound that section independently, so retrieved evidence cannot silently displace required Enterprise, Strategy, Work, Decision or Memory context.

Retrieval observability records structural diagnostics through the application boundary and excludes retrieved content from logs. Advanced ingestion, provider expansion, learned ranking and autonomous retrieval optimization remain deferred.