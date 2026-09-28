# CR8OR Domain Model

This document defines the initial bounded domains and known conceptual entities. It distinguishes persistent business/governance records from executable runtime components and deliberately avoids database columns, migrations and APIs before relevant product work is implemented.

## Identity & Access

**Purpose:** Establish identity, organization isolation and authority.

**Persistent entities:** Organization, User, Membership, Role, Permission, Access Policy, Approval Authority.

**Runtime concepts:** Agent identity and Agent capability are runtime/governance concepts and are represented through separate persistent assignment, descriptor, permission and capability records where implementation requires persistence.

**Relationships:** Users belong to organizations through memberships. Roles grant permissions. Agent identities operate within an organization and receive explicitly assigned capabilities and permissions.

**Ownership:** CR8OR.

**Known invariants:** Organization isolation is mandatory. Authorization is enforced server-side.

**Phase 1 implementation:** Membership is the authoritative user-to-organization link. Membership roles are `owner`, `admin`, and `member`, with role checks enforced server-side through native Laravel authorization. The membership record is updated for normal role administration rather than deleted and recreated.

**Deferred:** Full role hierarchy, permission catalogue and broader tenancy infrastructure.

## Enterprise

**Purpose:** Represent the enterprise being operated.

**Persistent entities:** Enterprise, Enterprise Context, Vision, Mission, Goal, KPI, Product, Customer, Partner, Competitor, Enterprise Decision.

**Relationships:** An enterprise has structured context, strategic direction, operational entities and recorded decisions. Enterprise Context is a dedicated one-to-one contextual record rather than part of enterprise identity.

**Ownership:** CR8OR.

**Known invariants:** Enterprise-owned records remain attributable to their enterprise and therefore to its organization. Enterprise decisions retain historical actor identity and decision time.

**Phase 1 implementation:** Enterprise is organization-scoped through `organization_id`. Enterprise Context is a dedicated one-to-one model. Products, customers, partners, goals, KPIs and enterprise decisions belong to an enterprise and are authorized through the enterprise's organization membership.

**Deferred:** Detailed CRM, product catalog and KPI calculation behavior.

## Agent Runtime & Governance

**Purpose:** Define executable Agents and Experts and the persistent records required to register, assign, authorize and audit them.

**Runtime components:** Agent PHP classes, Expert PHP classes, Capabilities, Operations and application/domain services.

**Persistent entities:** AgentDescriptor, ExpertDescriptor, Agent Instruction, Agent Permission, Agent Assignment, Agent Execution, Agent Decision, Agent Memory / Context Reference.

**Relationships:** An AgentDescriptor identifies an Agent runtime class. An ExpertDescriptor identifies an Expert runtime class. Agents coordinate Experts. Runtime components request capabilities through controlled application boundaries. Persistent execution, decision, assignment, permission and governance records capture the operational state around that runtime.

**Ownership:** CR8OR.

**Known invariants:**
- Runtime Agents and Experts are not Eloquent models.
- Descriptors identify runtime classes but do not replace them.
- Runtime metadata and behavior are authoritative in PHP code.
- Persistent descriptors must not become a duplicate editable source of runtime truth.
- Agent or Expert invocation never grants authority by itself.
- State-changing operations remain subject to server-side authorization and approval policy.

**Verified Phase 2 implementation:** Agent/Expert runtime contracts, descriptor registration, organization/enterprise-scoped assignments, capability permissions, execution and decision records, approval enforcement and Filament governance administration are implemented. Runtime classes remain authoritative for behavior and metadata.

**Verified Phase 4 implementation:** Provider abstraction, governed production execution, authorized Enterprise/Knowledge/Strategy/Work context assembly, Expert coordination, MCP capability invocation boundaries, provider failure handling, correlation and execution audit contracts are implemented.

**Verified Phase 7.2 implementation:** The five core business Agent runtimes (CEO/Orchestration, Marketing, Finance, Product and Operations) and the minimum supporting Expert runtimes are implemented as non-persistent PHP components and registered through AgentDescriptor and ExpertDescriptor. Their runtime metadata remains authoritative in code and invocation does not grant execution authority.

**Verified Phase 7.3 implementation:** AgentDelegation persists governed cross-Agent work with source/target assignment references, Enterprise/organization scope, historical identity snapshots, parent AgentExecution, correlation, idempotency and explicit pending/running/succeeded/failed lifecycle. The receiving Agent execution remains authoritative in AgentExecution and is linked back to the delegation. Retries reuse the same delegation identity.

**Verified Phase 7.4 implementation:** ApprovalRequest may be immutably bound to an AgentDelegation for governed source or target approval. Authorization verifies the delegation identity and applicable assignment/context, while consumption records the exact AgentExecution that used the approval and prevents replay against another execution.

**Verified Phase 7.5 implementation:** `MultiAgentBusinessReportingService` provides an Enterprise-authorized, read-only derived collaboration report from AgentExecution, AgentDecision, AgentDelegation, ApprovalRequest, Workflow and existing FinancialReport/BusinessHealthResult records. The report summarizes Agent activity, delegation and workflow status, approval outcomes, explicit failures and existing business-domain result references without creating a second source of truth. Historical Agent identity is read from immutable execution/delegation snapshots.

**Verified Phase 7.6 implementation:** The Phase 7 administration surface exposes AgentDelegation history and the derived collaboration report through existing Filament authorization and organization-scoping patterns. Existing execution, decision, approval and workflow records remain read-only where their historical semantics require it, and runtime Agent/Expert metadata remains authoritative in PHP.

**Deferred:** Generalized reporting engines, forecasting, analytics platforms, visualization and cross-enterprise reporting.

**Verified Phase 8.18 implementation:** `AgentEpisodicMemory` stores bounded summaries of explicit meaningful Agent experiences. Each record is Organization/Enterprise scoped, references the authoritative `AgentExecution`, preserves provenance, and is retrieved through an authorization-aware deterministic boundary. Episodic memory does not replace or duplicate the authoritative execution record.

**Verified Phase 8.19 implementation:** `AgentSemanticMemory` stores durable learned statements scoped to an Organization, Enterprise and Agent descriptor. Each memory has explicit confidence/status, authoritative source provenance, immutable creation/update versions and explicit conflict references. `AgentSemanticMemoryService` authorizes Enterprise/Agent access, bounds retrieval, preserves prior versions on updates and marks contradictory memories as disputed without deleting either statement. Semantic memory remains distinct from Knowledge and authoritative business state.

**Verified Phase 8.20–8.22 implementation:** `AgentMemoryPolicy` centralizes authorization and terminal-execution rules for memory reads/writes; `AgentMemoryService` provides one governed retrieval/write boundary over episodic and semantic memory; retrieval limits are bounded server-side. `MemoryContextProvider` makes memory an explicit, assignment-scoped context requirement with topic/relevance filters and a bounded combined budget. Memory provenance exposes the authoritative source execution and historical Agent identity without replacing execution history.

**Deferred:** Generalized cross-Agent workflow orchestration, broader policy language, generalized retrieval/indexing beyond the bounded memory/context providers, and detailed future execution/provider schemas.

## Knowledge

**Purpose:** Store durable business knowledge and references.

**Core entities:** Knowledge Source, Document, Knowledge Item, Context, Decision Record, Specification, Reference, Knowledge Version.

**Relationships:** Knowledge sources contain or reference durable knowledge; versions preserve historical interpretation.

**Ownership:** CR8OR.

**Known invariants:** Transient model context is not the authoritative knowledge store.

**Verified Phase 9.1 implementation:** KnowledgeRetrievalRequest, KnowledgeRetrievalResult and KnowledgeRetrievalResultItem define the canonical provider-neutral retrieval contract. KnowledgeRetrievalService authorizes the actor against the requested Enterprise before invoking the provider boundary, preserves correlation identity and requires bounded retrieval inputs. Retrieval results carry Knowledge Item, Source, Document, Context, Reference and Version provenance metadata. Storage, indexing and retrieval algorithms remain behind KnowledgeRetrievalProvider and are implemented by later Phase 9 work.

**Verified Phase 9.2 implementation:** `KnowledgeIndexRecord` is a lifecycle ledger for searchable representations. Each record preserves Enterprise, source, document, Knowledge Item and authoritative version identity plus representation key and lifecycle status (`pending`, `indexed`, `stale`, `failed`, `removed`). `KnowledgeIndexLifecycleService` authorizes every lifecycle operation, deterministically requeues current versions, stales superseded representations by authoritative version and unit key, preserves sibling chunks from the same version, records failures and prevents stale/removed representations from returning to indexed state. The lifecycle model intentionally stores no authoritative Knowledge content.

**Verified Phase 9.3 implementation:** `KnowledgeContentNormalizationService` converts supported Knowledge documents and versioned items into deterministic bounded `KnowledgeNormalizedUnit` values. Canonicalization normalizes line endings/whitespace, preserves heading paths and reference metadata, bounds large content by deterministic word-aware character limits, and carries Enterprise/source/document/item/version provenance. Empty content produces no units, and changed authoritative versions produce different version identity. The normalization layer contains no ranking, embedding or generated-summary behavior.

**Verified Phase 9.4 implementation:** `KnowledgeIndexingService` is the governed application boundary that persists normalized searchable units, coordinates the indexing lifecycle, records non-content-bearing failure state and supports idempotent reindexing. Searchable unit content lives only in the representation layer and remains tied to Enterprise, Knowledge Item and authoritative version identity; lifecycle records remain responsible for indexed/stale/failed/removed state.

**Verified Phase 9.5 implementation:** `KnowledgeLexicalRetrievalProvider` implements deterministic lexical retrieval over current indexed representations. Candidate filtering is Enterprise-scoped and limited to indexed lifecycle records before scoring; results are bounded by the canonical retrieval request, carry source/document/version/reference metadata, and return deterministic empty results for empty/unmatched queries. Authorization remains enforced by `KnowledgeRetrievalService` before provider execution. Semantic and model-based ranking remain outside this boundary.

**Verified Phase 9.6 implementation:** `KnowledgeEmbeddingProvider` isolates embedding generation from retrieval. `KnowledgeEmbedding` stores only searchable representation identity, embedding version, content hash and vector data, while `KnowledgeSemanticIndexService` authorizes and indexes only current indexed representations. `KnowledgeSemanticRetrievalProvider` filters by Enterprise, indexed lifecycle state, embedding version and content hash before cosine ranking, and returns canonical provenance. A deterministic fake-compatible provider path is covered by tests; no vendor or vector database is authoritative.

**Verified Phase 9.7 implementation:** `KnowledgeHybridRetrievalProvider` combines lexical and semantic candidate sets behind the canonical retrieval contract. It uses deterministic 0.5/0.5 signal weighting, deduplicates by Knowledge Item, preserves provenance from either source, applies the final result bound and uses Knowledge Item identity as the stable tie-breaker. Authorization remains owned by `KnowledgeRetrievalService`, and ranking remains replaceable rather than model-trained.

**Verified Phase 9.8 implementation:** `KnowledgeRetrievalProvenanceService` rehydrates retrieved Knowledge Items from the authoritative Enterprise scope, re-applies Knowledge authorization, fills source/document/version/reference metadata from authoritative records when providers omit it, and marks whether returned version identity is current. Provider results pointing outside the requested Enterprise are rejected. Provenance normalization remains separate from generated interpretation and publication.

**Verified Phase 9.9 implementation:** The canonical `KnowledgeRetrievalService` applies provenance normalization after every provider result, so Enterprise scope and Knowledge authorization are enforced after provider execution as well as before it. Security regression coverage verifies unauthorized Enterprise requests, provider-supplied foreign Knowledge IDs and hybrid provider fixtures. Provider implementations cannot bypass the authoritative Knowledge boundary by returning fabricated or cross-Enterprise identifiers.

**Deferred:** Retrieval/indexing implementation.

## Strategy

**Purpose:** Represent intended business outcomes and strategic direction.

**Core entities:** Objective, Strategy, Plan, Initiative, KPI, Metric, Strategic Decision.

**Verified Phase 3.5 implementation:** Decision records persist operational or strategic decisions independently of AgentDecision and EnterpriseDecision. Each decision belongs to an Enterprise, may reference explicit Objective/Strategy/Plan/Initiative and Project/Task/Work Item context, and snapshots actor identity and decision-time context. Historical actor, context, type and decision timestamp cannot be rewritten after creation. Server-side authorization follows the Enterprise organization boundary.

**Deferred:** Generalized policy engines, broader decision automation and polymorphic decision context.

**Relationships:** Objectives are pursued through strategies, plans and initiatives and measured through metrics.

**Ownership:** CR8OR.

**Known invariants:** Strategy remains connected to business and operational context.

**Phase 3.2 implementation:** Objective belongs to an Enterprise and may reference existing Goal and KPI records; Strategy belongs to Objective; Plan belongs to Strategy; Initiative belongs to Plan. These relationships are explicit foreign keys and are authorized through the Objective Enterprise organization boundary.

**Deferred:** Planning methodology and metric calculation details.

## Work

**Purpose:** Represent operational activity.

**Core entities:** Project, Task, Work Item, Assignment, Milestone, Dependency, Workflow, Job, Execution.

**Verified Phase 3.3 implementation:** Projects belong to an Enterprise and may reference Strategy, Plan and Initiative records from that same Enterprise. Tasks and Work Items belong to an Enterprise and may belong to a Project within that Enterprise. Tasks may form a parent/child hierarchy. Milestones belong to a Project and Enterprise. Dependencies belong to an Enterprise and relate predecessor/successor work records through explicit polymorphic references. Assignments belong to an Enterprise and identify either a User or an existing Agent Assignment as the assignee.

**Authorization:** Work records inherit the Enterprise organization boundary. Server-side policies prevent cross-organization access and management. Assignments represent an assignee only and do not grant authority beyond the assignee's existing permissions.

**Ownership:** CR8OR for CR8OR-owned work.

**Known invariants:** Parent ownership is preserved when unrelated work fields change. Work relationships remain attributable to their Enterprise. Assignment does not change authorization.

**Verified Phase 3.4 implementation:** Workflows, Jobs and Executions persist CR8OR-owned execution intent and traceability. Workflows retain Enterprise and optional Project/Task/Work Item origin; Jobs carry a unique idempotency key and retry-safe lifecycle; Executions snapshot the originating organization, enterprise and optional Project/Task/Work Item context and record the lifecycle of a Job attempt. Pending, running, succeeded and failed transitions are explicit, terminal states cannot be silently rewritten, and operational records remain organization-scoped.

**Deferred:** Full workflow-engine semantics, concrete provider execution and external task-management integration.

## Marketing

**Purpose:** Represent marketing strategy and controlled content operations.

**Core entities:** Marketing Strategy, Campaign, Content Series, Content Item, Script, Channel, Audience, Publication, Content Metric.

**Relationships:** Campaigns contain content series and content items; Content Items may contain Scripts; publications connect approved content to channels. AI-derived Content Items may retain provenance to an AgentExecution and AgentDecision without duplicating execution state.

**Ownership:** CR8OR for business state; external systems execute specialized publishing or media operations.

**Known invariants:** Content lifecycle transitions are enforced server-side. Content moves through draft → in-review → approved → publication-ready, with archival as a terminal path. Publication readiness requires an explicit matching approval and cannot be set by a raw status update. Approved/publication-ready content cannot be silently rewritten. AI-generated content enters as derived draft state until accepted through the normal review/approval workflow. Agent execution is constrained by organization, enterprise, assignment, capability and approval context.

**Verified Phase 5 implementation:** Content lifecycle/application services, AI-assisted generation/revision through the existing AgentExecutionService and ModelProvider boundary, AgentExecution/AgentDecision provenance, governed MCP content capabilities, server-side publication-readiness approval, and the CR8OR-owned publishing lifecycle are implemented. Postiz and other external services remain execution boundaries.

## Media

**Purpose:** Manage media requests, versions, generation and rendering state.

**Core entities:** Asset, Asset Version, Generation Request, Generation Job, Transformation, Render Request, Render Job, Render Output, Media Metadata.

**Relationships:** Requests produce jobs and outputs; versions preserve media history.

**Ownership:** CR8OR owns lifecycle state and references; specialized services execute generation/rendering.

**Known invariants:** Outputs remain associated with their originating request and source content where applicable; asset versions preserve prior outputs; generation/render failures cannot become successful lifecycle state.

**Verified Phase 5.3 implementation:** Asset/version history, generation and render requests/jobs, execution correlation, deterministic failure/retry handling and the provider-neutral media storage boundary are implemented. Specialized generators/renderers and storage providers remain execution boundaries.

## Publishing

**Purpose:** Separate publication lifecycle from content creation and provider execution.

**Core entities:** Channel, Social Account, Publication, Publication Schedule, Publishing Job, Publication Result, Engagement Metric.

**Relationships:** Approved content is scheduled and executed against a channel/account; results are recorded.

**Ownership:** CR8OR owns publication state; providers execute delivery.

**Known invariants:** Publication results are correlated to their originating publication; publication history is immutable; publication readiness and required approval are enforced before execution.

**Verified Phase 5.4 implementation:** CR8OR-owned scheduling/submission/reconciliation, Postiz provider isolation, idempotency, normalized failures and historical publication results are implemented. Postiz remains an execution boundary.

## Finance

**Purpose:** Represent financial records and derived financial reporting.

**Core entities:** Financial Account, Transaction, Transaction Category, Statement, Invoice, Expense, Revenue, Budget, Financial Period, Financial Report.

**Relationships:** Transactions belong to accounts and periods; reports derive from authoritative records.

**Ownership:** CR8OR.

**Known invariants:** Financial history is auditable and remains interpretable. Statements preserve source/import records, remain attributable to their organization, Enterprise and Financial Account, and may reference authoritative Transactions without replacing or rewriting transaction history. Source/provider identifiers are attribution and idempotency metadata, not business-state authority.

**Verified Phase 6.2 implementation:** Financial Accounts, Transactions and Transaction Categories provide the authoritative ledger foundation. Statements and Statement Entries preserve imported/source records, enforce Enterprise/organization/account scope, prevent duplicate source identifiers, and optionally link entries to authoritative Transactions.

**Verified Phase 6.3 implementation:** Invoices, Revenue and Expense are CR8OR-owned financial records with Enterprise-safe relationships, fixed-precision monetary values, invoice counterparty snapshots and lifecycle status that does not imply payment. Revenue and Expense may reference authoritative Financial Accounts and Transactions without duplicating ledger state; cross-Enterprise relationships are rejected and organization authorization is enforced through Enterprise policies.

**Verified Phase 6.4 implementation:** Financial Periods provide explicit Enterprise-owned reporting boundaries with preserved historical identity, while Budgets remain planning records scoped to a period and optional account/category. Transactions, Revenue and Expense can be associated with periods through Enterprise-safe relationships; budget changes do not mutate authoritative ledger records.

**Verified Phase 6.5 implementation:** Financial Reports and Business Health Results are derived records generated from authoritative financial accounts, transactions, revenue, expense, budget and period data. Reports require an Enterprise-owned period and support optional account/category scopes. Monetary calculations use fixed four-decimal string arithmetic, preserve a minimal source snapshot, and never mutate source records. Agent financial context is assembled through the existing authorization-aware context service and exposes Enterprise-scoped account/period metadata plus latest-period derived metrics only when a single currency makes the result unambiguous. Calculation failures do not persist successful report/results.

**Deferred:** Accounting rules, automated reconciliation and provider integrations.

## Integrations

**Purpose:** Represent connections and execution state involving external systems.

**Core entities:** Integration, Provider, Credential Reference, Connection, Webhook, External Resource, Integration Event, Integration Job.

**Relationships:** Connections link CR8OR to providers; external resources/events/jobs correlate to the relevant integration.

**Ownership:** CR8OR owns integration configuration and recorded integration state.

**Known invariants:** Credentials are references, not plaintext secrets in domain records. External operations consider retries and idempotency.

**Verified Phase 11.1 implementation:** Provider-neutral integration definitions, provider metadata, credential references, external execution context, webhook/event envelopes and the IntegrationConnection lifecycle are explicit application contracts. **Verified Phase 11.2 implementation:** External IntegrationResult records provide immutable webhook/poll reconciliation history with idempotent deduplication, deterministic late/out-of-order handling and governed IntegrationJob lifecycle transitions. Provider-specific execution remains in adapters; external-result reconciliation and durable platform event delivery remain separate phases.

## Governance

**Purpose:** Control authority, approvals, exceptions and auditability.

**Core entities:** Approval Request, Policy, Audit Entry, Decision, Exception, Change Record. Approval state is persisted on the request for the current Agent capability approval boundary.

**Relationships:** Requests may require approvals; actions produce audit records and decisions.

**Ownership:** CR8OR.

**Known invariants:** Sensitive operations are attributable and auditable. Agent capability permissions may require approval; approval requests are scoped to organization, enterprise, Agent assignment, actor, capability and execution/target context; delegated approvals additionally bind to the exact AgentDelegation; only authorized organization approvers may decide them; approved requests expire and consumed approvals cannot be replayed outside their recorded context.

**Verified Phase 3.5 implementation:** Decision records are an explicit governance record distinct from AgentDecision and EnterpriseDecision. They preserve actor identity, decision-time context and timestamp as historical fields and remain scoped to the Enterprise organization.

**Deferred:** The broader policy language and approval matrix remain deferred; the Phase 2 Agent capability boundary uses an explicit per-permission approval requirement.

## Reporting

**Purpose:** Provide derived views of authoritative business data.

**Core entities:** Report, Metric, Snapshot, Dashboard, Business Health Result, Performance Result.

**Relationships:** Reports and dashboards derive from authoritative domain records and may use snapshots for historical interpretation.

**Ownership:** CR8OR.

**Known invariants:** Reports do not replace or rewrite authoritative records.

**Deferred:** Reporting engine and visualization implementation.

## Cross-Domain Rules

- Identity & Access establishes authority used by every other domain.
- Enterprise is the principal operational context for Agents and work.
- Agents and Experts consume authorized knowledge and strategy but do not own those domains.
- Runtime components invoke controlled capabilities rather than directly mutating business records.
- Governance constrains mutations in every domain.
- Integrations record external execution without transferring business-state ownership.
- Reporting derives from domain state and does not become a source of truth.
- These boundaries are conceptual at this stage and do not require separate Laravel packages, schemas or services.

## Deliberately Deferred

No database columns, migrations, REST endpoints, MCP schemas, queue payloads or provider-specific APIs are defined here unless introduced by the relevant implementation phase.
**Verified Phase 9.10 implementation:** `RetrievedKnowledgeContextProvider` integrates the canonical `KnowledgeRetrievalService` into Agent context as a separate `retrieved_knowledge` section. Retrieval requires an explicit query or objective, uses bounded result limits and an explicit budget, preserves canonical retrieval provenance, and applies deterministic serialized-size token estimation with stable truncation. The section has its own budget and therefore cannot silently displace Enterprise, Strategy, Work, Decision, Memory or other required context sections. The application container binds the provider-neutral retrieval contract to the governed lexical/semantic hybrid implementation.
**Verified Phase 9.11 implementation:** `KnowledgeRetrievalObservability` records correlation, Enterprise scope, requested mode, candidate/result counts, latency and an allowlisted subset of provider metadata at the canonical retrieval application boundary. It deliberately excludes retrieved titles, summaries, bodies, references and arbitrary provider metadata from logs. Provider, validation and provenance failures are recorded separately from downstream Agent execution failures using the same correlation identifier and error class only. Lexical, semantic and hybrid paths remain covered by deterministic regression tests.
## Strategic Context

Enterprise strategic context is first-class and enterprise-scoped. Vision, Mission and Competitor records are append-only versions with explicit `version`, `is_current`, effective dates and supersession links. Replacing strategic context archives the previous version rather than rewriting history.

`StrategicContextService` is the write boundary for these records. Agents receive only the current Vision, Mission and active Competitor versions through the canonical EnterpriseContextAssembler. Historical versions remain queryable for audit and interpretation but are not injected into normal Agent context unless explicitly requested by a future capability.