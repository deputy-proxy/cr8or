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

**Deferred:** Provider abstraction and production execution, memory implementation, agent-to-agent collaboration, broader policy language and detailed future execution/provider schemas.

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

**Relationships:** Objectives are pursued through strategies, plans and initiatives and measured through metrics.

**Ownership:** CR8OR.

**Known invariants:** Strategy remains connected to business and operational context.

**Deferred:** Planning methodology and metric calculation details.

## Work

**Purpose:** Represent operational activity.

**Core entities:** Project, Task, Assignment, Workflow, Work Item, Milestone, Dependency, Job, Execution.

**Relationships:** Projects contain work; tasks may be assigned; workflows and jobs coordinate execution.

**Ownership:** CR8OR for CR8OR-owned work.

**Known invariants:** Important work execution is traceable.

**Deferred:** Full workflow engine semantics.

## Marketing

**Purpose:** Represent marketing strategy and content operations.

**Core entities:** Marketing Strategy, Campaign, Content Series, Content Item, Script, Channel, Audience, Publication, Content Metric.

**Relationships:** Campaigns contain content series and content items; publications connect approved content to channels.

**Ownership:** CR8OR for business state; external systems execute specialized publishing or media operations.

**Known invariants:** Published content remains traceable to its CR8OR source.

**Deferred:** Channel-specific APIs and campaign automation.

## Media

**Purpose:** Manage media requests, versions, generation and rendering state.

**Core entities:** Asset, Asset Version, Generation Request, Generation Job, Transformation, Render Request, Render Job, Render Output, Media Metadata.

**Relationships:** Requests produce jobs and outputs; versions preserve media history.

**Ownership:** CR8OR owns lifecycle state and references; specialized services execute generation/rendering.

**Known invariants:** Outputs remain associated with their originating request and source content where applicable.

**Deferred:** Provider-specific media schemas.

## Publishing

**Purpose:** Separate publication lifecycle from content creation and provider execution.

**Core entities:** Channel, Social Account, Publication, Publication Schedule, Publishing Job, Publication Result, Engagement Metric.

**Relationships:** Approved content is scheduled and executed against a channel/account; results are recorded.

**Ownership:** CR8OR owns publication state; providers execute delivery.

**Known invariants:** Publication results are correlated to their originating publication.

**Deferred:** Provider-specific publishing behavior.

## Finance

**Purpose:** Represent financial records and derived financial reporting.

**Core entities:** Financial Account, Transaction, Transaction Category, Statement, Invoice, Expense, Revenue, Budget, Financial Period, Financial Report.

**Relationships:** Transactions belong to accounts and periods; reports derive from authoritative records.

**Ownership:** CR8OR.

**Known invariants:** Financial history is auditable and remains interpretable.

**Deferred:** Accounting rules, reconciliation and provider integrations.

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