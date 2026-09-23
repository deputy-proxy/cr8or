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

**Runtime components:** Agent PHP classes, Expert PHP classes, Functions / application services.

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

**Deferred:** Agent memory implementation, agent-to-agent collaboration, broader policy language and detailed future execution/provider schemas.

## Knowledge

**Purpose:** Store durable business knowledge and references.

**Core entities:** Knowledge Source, Document, Knowledge Item, Context, Decision Record, Specification, Reference, Knowledge Version.

**Relationships:** Knowledge sources contain or reference durable knowledge; versions preserve historical interpretation.

**Ownership:** CR8OR.

**Known invariants:** Transient model context is not the authoritative knowledge store.

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

**Deferred:** Accounting rules, automated reconciliation and provider integrations.

## Integrations

**Purpose:** Represent connections and execution state involving external systems.

**Core entities:** Integration, Provider, Credential Reference, Connection, Webhook, External Resource, Integration Event, Integration Job.

**Relationships:** Connections link CR8OR to providers; external resources/events/jobs correlate to the relevant integration.

**Ownership:** CR8OR owns integration configuration and recorded integration state.

**Known invariants:** Credentials are references, not plaintext secrets in domain records. External operations consider retries and idempotency.

**Deferred:** Provider-specific contract models.

## Governance

**Purpose:** Control authority, approvals, exceptions and auditability.

**Core entities:** Approval Request, Policy, Audit Entry, Decision, Exception, Change Record. Approval state is persisted on the request for the current Agent capability approval boundary.

**Relationships:** Requests may require approvals; actions produce audit records and decisions.

**Ownership:** CR8OR.

**Known invariants:** Sensitive operations are attributable and auditable. Agent capability permissions may require approval; approval requests are scoped to organization, enterprise, Agent assignment, actor, capability and execution/target context; only authorized organization approvers may decide them; approved requests expire and cannot be reused outside their recorded context.

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