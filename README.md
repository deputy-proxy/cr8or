# CR8OR

CR8OR is an AI-native business operating platform that provides a persistent system of record for enterprises, together with a controlled MCP interface through which AI agents can understand enterprise context, make decisions, request actions, and execute approved workflows.

CR8OR is designed to separate **business state**, **AI reasoning**, **workflow orchestration**, and **external execution**. Laravel owns the authoritative business state and application rules; AI agents own reasoning and decisions; MCP exposes controlled capabilities to AI clients; n8n orchestrates asynchronous integrations; specialized external services perform media, publishing, development, and other execution tasks.

## Product Definition

CR8OR is:

- A business operating system built around explicit domain boundaries.
- A persistent system of record for enterprise context, strategy, work, content, media, finance, integrations, and operational history.
- An AI-native platform in which agents operate through controlled capabilities rather than direct database access.
- An MCP server and application interface for AI agents and AI clients.
- A workflow and governance layer connecting human decisions, AI decisions, application actions, background jobs, and external services.

CR8OR is not:

- A generic CRUD administration panel with an AI chatbot attached.
- An AI model or model-hosting platform.
- A replacement for specialized execution services such as media renderers, social publishers, or external APIs.
- A workflow engine whose business state lives primarily in n8n.
- A system where AI agents can bypass application authorization or domain rules.

The core architectural boundary is:

**Agents decide → CR8OR owns state and rules → MCP exposes capabilities → n8n orchestrates → external services execute.**

## Product Principles

The project is governed by the following principles:

1. **Business state belongs to CR8OR** — the Laravel application is the canonical source of truth for operational business state.
2. **Agents own decisions, not database access** — AI agents interact through explicit application capabilities.
3. **Domain behavior is explicit** — important business operations are represented by controlled application/domain workflows rather than arbitrary model mutation.
4. **MCP is an interface, not a second application layer** — MCP translates AI requests into application services and must not contain duplicated business logic.
5. **Human authority remains explicit** — sensitive actions can require approval and must be auditable.
6. **External systems are execution boundaries** — n8n, media workers, publishers, GitHub and other services perform specialized work without becoming the source of truth.
7. **Historical truth is preserved** — important decisions, state changes, approvals, transactions and outputs remain interpretable after rules change.
8. **CI is part of development** — an implementation is not complete until the repository's required quality gates pass and the resulting state is verified.
9. **Documentation is part of the product** — important architectural, domain, security and operational decisions must be documented.
10. **The architecture must remain model-independent** — AI models can change without changing the business system or losing its state.

## Primary Actors

**Human User**

Owns enterprise decisions, approvals, permissions, and organizational authority. Humans can inspect, approve, reject, correct, and override appropriate AI-generated work.

**AI Agent**

Performs a defined operational role such as CEO, Marketing, Finance, Product, Operations, or a specialized expert. Agents reason over authorized enterprise context and invoke controlled CR8OR capabilities.

**AI Expert**

Provides specialized reasoning within an agent or workflow, such as Marketing Expert, Copywriting Expert, SEO Expert, Finance Expert, Accounting Expert, Product Expert, Laravel Expert, or UX Expert.

**System Administrator**

Maintains platform configuration, organizations, users, permissions, integrations, infrastructure settings, and operational controls.

**External Service**

A connected system that performs specialized execution, such as n8n, CR8OR Media, R2, Canva, Postiz, GitHub, OpenAI or other approved providers.

## Target Core Domain Model

The product is organized around explicit domain boundaries rather than an uncontrolled collection of CRUD records. The entities below describe the intended CR8OR domain model; they are not all implemented in the current repository.

### Identity & Access

- Organization
- User
- Membership
- Role
- Permission
- Agent identity
- Agent capability
- Access policy
- Approval authority

Organizations provide the primary isolation boundary for business data and operational authority.

### Enterprise

- Enterprise
- Enterprise Context
- Vision
- Mission
- Goal
- KPI
- Product
- Customer
- Partner
- Competitor
- Enterprise Decision

An Enterprise represents the operational entity an agent is helping to run. Enterprise Context provides the structured information agents need without requiring them to reconstruct the enterprise from unrelated records.

### Agents

- Agent
- Expert
- Agent Instruction
- Capability
- Tool
- Agent Permission
- Agent Assignment
- Agent Execution
- Agent Decision
- Agent Memory / Context Reference

Agents are defined by role, instructions, available capabilities, permissions, model configuration, knowledge access and approval requirements.

### Knowledge

- Knowledge Source
- Document
- Knowledge Item
- Context
- Decision Record
- Specification
- Reference
- Knowledge Version

Knowledge provides durable enterprise context. The AI model is not the authoritative storage location for business knowledge.

### Strategy

- Objective
- Strategy
- Plan
- Initiative
- KPI
- Metric
- Strategic Decision

Strategy describes what the business intends to achieve and provides the context against which agents evaluate proposed actions.

### Work

- Project
- Task
- Assignment
- Workflow
- Work Item
- Milestone
- Dependency
- Job
- Execution

Work represents operational activity independently of the specific interface through which it was created.

### Marketing

- Marketing Strategy
- Campaign
- Content Series
- Content Item
- Script
- Channel
- Audience
- Publication
- Content Metric

Marketing is a business domain, not merely a collection of social-media posts.

### Media

- Asset
- Asset Version
- Generation Request
- Generation Job
- Transformation
- Render Request
- Render Job
- Render Output
- Media Metadata

Media assets have an explicit lifecycle and remain associated with their source requests, prompts, versions, jobs and outputs.

### Publishing

- Channel
- Social Account
- Publication
- Publication Schedule
- Publishing Job
- Publication Result
- Engagement Metric

Publishing is separated from content creation so that content can exist independently of a particular publishing provider.

### Finance

- Financial Account
- Transaction
- Transaction Category
- Statement
- Invoice
- Expense
- Revenue
- Budget
- Financial Period
- Financial Report

Financial records require strong auditability and historical integrity.

### Integrations

- Integration
- Provider
- Credential Reference
- Connection
- Webhook
- External Resource
- Integration Event
- Integration Job

Integrations connect CR8OR to external systems without transferring ownership of business state to those systems.

### Governance

- Approval
- Approval Request
- Policy
- Audit Entry
- Decision
- Exception
- Change Record

Sensitive actions must be traceable from request through decision, execution and result.

### Reporting

- Report
- Metric
- Snapshot
- Dashboard
- Business Health Result
- Performance Result

Reports provide derived views of authoritative business data and must not silently replace the underlying records.

## Target Operating Architecture

CR8OR follows the following architectural model. This describes the target system architecture; implementation is being introduced incrementally by product phase.

```
                         HUMAN
                           |
                           v
                    AI Client / UI
                           |
                           v
                    +-------------+
                    |   AI Agent  |
                    +------+------+
                           |
                         MCP
                           |
                           v
                    +-------------+
                    |   CR8OR     |
                    |   Laravel   |
                    +------+------+ 
                           |
              +------------+------------+
              |            |            |
              v            v            v
           Domain      Application    Policies
           Model        Services      / Auth
              |            |
              +------+-----+
                     |
              +------+------+
              |             |
              v             v
            Events        Jobs
                            |
                            v
                           n8n
                            |
          +-----------------+------------------+
          |                 |                  |
          v                 v                  v
       Media             Storage          Publishing
       Workers             R2              Postiz
          |
       Canva / AI / other
       specialized services
```

### Architectural Responsibilities

| Layer | Responsibility |
|---|---|
| AI Agents | Reasoning, planning, decisions and recommendations |
| CR8OR Laravel | Authoritative business state, domain rules, permissions and history |
| MCP | Controlled AI-facing capability interface |
| Application Services | Execute explicit business operations |
| Policies / Authorization | Enforce authority and data boundaries |
| Events | Communicate meaningful state changes |
| Jobs | Perform asynchronous application work |
| n8n | Orchestrate external workflows and integrations |
| External Workers | Perform specialized execution |
| R2 / Storage | Store canonical generated media and files |
| Filament | Administrative and operational application interface |

### Core Interaction Pattern

Every important operation should follow the conceptual pattern:

**Entity → Action → Job → Event → Approval → Result**

Not every operation requires every step, but the boundaries must remain explicit.

## MCP Architecture

MCP is a first-class interface to CR8OR, but it does not own the business domain.

### MCP Resources

Resources provide authorized context to AI clients.

Examples:

- `enterprise://{enterprise}`
- `enterprise://{enterprise}/context`
- `enterprise://{enterprise}/strategy`
- `enterprise://{enterprise}/marketing`
- `enterprise://{enterprise}/finance`
- `enterprise://{enterprise}/kpis`
- `enterprise://{enterprise}/projects`

### MCP Tools

Tools expose explicit capabilities.

Examples:

- `get_enterprise_context`
- `create_campaign`
- `create_content_item`
- `request_asset`
- `create_render_job`
- `schedule_publication`
- `create_task`
- `register_transaction`
- `request_approval`
- `get_business_metrics`

MCP tools must call application/domain services rather than directly manipulating Eloquent models or database records.

### MCP Prompts / Workflows

Where appropriate, reusable operational workflows may be exposed for tasks such as:

- weekly marketing planning;
- financial review;
- business health review;
- product launch;
- content campaign planning;
- operational review.

The MCP surface must remain capability-oriented rather than exposing internal database structure.

## Agent Architecture

Agents are persistent operational roles.

Examples:

- CEO Agent
- Marketing Agent
- Finance Agent
- Product Agent
- Operations Agent

Experts provide specialized capabilities to agents.

Examples:

- Marketing Expert
- Copywriting Expert
- SEO Expert
- Finance Expert
- Accounting Expert
- Product Expert
- Laravel Expert
- UX Expert

Agents and experts must have explicit:

- identity;
- role;
- instructions;
- capabilities;
- permissions;
- model configuration;
- knowledge access;
- approval requirements;
- execution history.

An agent must never gain authority merely because an AI model can technically call a tool.

## Core Business Lifecycle

The operational lifecycle of an AI-assisted business action is:

**Enterprise Context**
↓
**Agent Intent**
↓
**Plan**
↓
**Capability Request**
↓
**Authorization**
↓
**Approval, where required**
↓
**Execution**
↓
**Result**
↓
**Event**
↓
**Updated Business State**
↓
**Audit / Reporting**

Lifecycle rules:

- Every state-changing operation must be authorized.
- Sensitive actions must support explicit approval gates.
- External execution must be traceable back to the originating CR8OR action.
- External service failures must not silently corrupt authoritative CR8OR state.
- Retries must be idempotent where the operation permits retries.
- Important historical records must remain interpretable.
- AI output is not authoritative merely because it was generated successfully.

## Methodology

### CR8OR Operating Model

CR8OR separates four responsibilities:

**Reason**

AI agents interpret enterprise context and determine what should happen.

**Control**

CR8OR applies authorization, domain rules, policies and approval requirements.

**Orchestrate**

n8n coordinates asynchronous and multi-service workflows.

**Execute**

Specialized services perform rendering, generation, storage, publishing, development and other concrete operations.

This separation is authoritative for the initial architecture and should be documented further as implementation decisions are made.

## Public Trust Model

CR8OR's authoritative record is the Laravel application database and its associated immutable/versioned records.

External services may provide execution results, but they do not become authoritative merely by holding a copy of the data.

AI-generated plans, recommendations, prompts and outputs are derived artifacts until accepted or executed through the appropriate CR8OR workflow.

Important state changes must be attributable to:

- actor;
- agent, where applicable;
- action;
- timestamp;
- authorization context;
- approval;
- external execution reference;
- resulting state.

## Verified Current State

The repository has completed its foundation phase and is now ready to begin the first business-domain implementation.

### Implemented

- Laravel 13 application foundation.
- Filament 5 administration foundation.
- Repository conventions and issue-driven development workflow.
- GitHub Actions CI and configured PHP/frontend validation.
- Core architecture, domain, governance, MCP, integration and security documentation.
- Repository-level AI development rules in `.github/AI_DEVELOPMENT_RULES.md`.
- The verified baseline currently contains the Laravel/authentication foundation and the `User` model.

### Not Yet Implemented

The following remain product-roadmap work rather than completed runtime functionality:

- Organizations and memberships.
- Enterprise and enterprise context.
- Runtime agents and experts.
- Agent capabilities, authority and governance runtime.
- Strategy, knowledge and work domains.
- MCP server implementation and tool/resource runtime.
- Marketing, media and publishing domains.
- Finance and business operations.
- Multi-agent orchestration.

This distinction is deliberate. The repository is a verified foundation plus architectural specification, not a partially implemented version of every future domain.

### Foundation Integrity

During Phase 0, a later change accidentally removed `.github/AI_DEVELOPMENT_RULES.md`. The Phase 0 audit detected the regression and restored the file before Phase 0 was closed. The lesson is operational: required foundation files must be protected by automated integrity validation rather than relying on human memory or review alone.

### Immediate Priority

**Phase 1 — Identity, Organizations & Enterprise Context**

The next implementation work should establish organization isolation, memberships, authorization, enterprise identity and persisted enterprise context before runtime agents, MCP tools or multi-agent workflows are introduced.

## Development Roadmap

The phases below represent the original **product development roadmap**.

The roadmap is product-oriented. Technical implementation issues, tickets and temporary engineering groupings must not silently replace or renumber the original product phases.

### Phase 0 — Foundation & Architecture

**Status: Complete**

**Objective**

Establish the fresh Laravel + Filament application and the architectural foundation for CR8OR Core.

**Scope**

- Fresh Laravel application.
- Filament administration foundation.
- Repository and CI conventions.
- Core architecture documentation.
- Target domain and application boundaries.
- Development rules.
- Initial authorization and MCP architecture specifications.

**Completion Criteria**

- Repository baseline is established.
- CI validates the baseline.
- Core architectural specifications exist.
- Domain boundaries are documented.
- Development workflow is documented.
- The repository baseline follows the agreed foundation conventions.

### Phase 1 — Identity, Organizations & Enterprise Context

**Status: Not started**

**Objective**

Create the authoritative organization, user, business and business-context foundation.

**Scope**

- Organizations and memberships.
- Users, roles and permissions.
- Enterprises.
- Enterprise context.
- Products, customers and partners.
- Goals and KPIs.
- Enterprise decisions.

**Completion Criteria**

- Organization isolation is enforced.
- Business context can be created and maintained.
- Authorization is server-side.
- Core domain invariants are tested.
- Administrative interfaces are operational.

### Phase 2 — Agents, Experts & Governance

**Status: Not started**

**Objective**

Create the persistent AI-agent operating model.

**Scope**

- Agents.
- Experts.
- Instructions.
- Capabilities.
- Agent permissions.
- Knowledge access.
- Agent execution records.
- Approval requirements.
- Audit records.

**Completion Criteria**

- Agents have explicit identities and roles.
- Capabilities are permission-controlled.
- Agent actions are auditable.
- Sensitive operations can require approval.
- Agent execution history is persisted.

### Phase 3 — Strategy, Knowledge & Work

**Status: Not started**

**Objective**

Give agents and humans a structured operating model for planning and execution.

**Scope**

- Knowledge.
- Strategy.
- Objectives.
- Plans.
- Projects.
- Tasks.
- Workflows.
- Jobs.
- Decisions.

**Completion Criteria**

- Business knowledge is persistently represented.
- Strategy can be connected to operational work.
- Tasks and projects can be assigned.
- Important decisions are recorded.
- Background execution is traceable.

### Phase 4 — MCP Core

**Status: Not started**

**Objective**

Expose CR8OR as a controlled AI operating interface.

**Scope**

- MCP server foundation.
- Authentication.
- Authorization.
- Resources.
- Tools.
- Agent context.
- Application-service integration.
- MCP auditability.
- Error and validation contracts.

**Completion Criteria**

- MCP clients can retrieve authorized enterprise context.
- MCP tools invoke application services.
- MCP cannot bypass authorization.
- Tool execution is auditable.
- Business logic is not duplicated inside MCP.

### Phase 5 — Marketing, Media & Publishing

**Status: Not started**

**Objective**

Implement the AI-assisted content operating system.

**Scope**

- Marketing strategy.
- Campaigns.
- Content series.
- Scripts.
- Content items.
- Asset requests.
- Generation jobs.
- Media rendering.
- R2 storage.
- Publishing.
- Postiz integration.
- Canva-assisted workflows.

**Completion Criteria**

- Content can move through a controlled lifecycle.
- Asset generation is traceable.
- Render outputs are associated with source content.
- Published content is traceable to CR8OR state.
- External media and publishing services remain execution boundaries.

### Phase 6 — Finance & Business Operations

**Status: Not started**

**Objective**

Extend CR8OR into broader business operations and financial intelligence.

**Scope**

- Financial accounts.
- Transactions.
- Statements.
- Revenue.
- Expenses.
- Invoices.
- Budgets.
- Financial reporting.
- Operational metrics.
- Business health reporting.

**Completion Criteria**

- Financial records are auditable.
- Historical transactions remain interpretable.
- Financial operations respect authorization.
- Agents can access authorized financial context.
- Reports derive from authoritative records.

### Phase 7 — Multi-Agent Business Operations

**Status: Not started**

**Objective**

Allow multiple specialized agents to collaborate through a shared business operating system.

**Scope**

- CEO / orchestration agent.
- Marketing agent.
- Finance agent.
- Product agent.
- Operations agent.
- Expert delegation.
- Agent-to-agent workflows.
- Cross-domain approvals.
- Business-level reporting.

**Completion Criteria**

- Agents can delegate according to explicit authority.
- Cross-domain workflows are auditable.
- Human approval remains available for sensitive decisions.
- Agent collaboration does not bypass domain boundaries.
- Business state remains centralized in CR8OR.

## Current Reconciliation

The repository is currently a **fresh CR8OR Laravel + Filament installation** and the implementation roadmap has not yet begun beyond the initial foundation.

| Original phase | Current status | Reconciliation |
|---|---|---|
| Phase 0 — Foundation & Architecture | **Complete** | Fresh application, CI, architecture specifications, development rules, and application-layer boundaries are verified. Phase 1 remains unimplemented. |
| Phase 1 — Identity, Organizations & Enterprise Context | **Not started** | No product capability should be considered complete yet. |
| Phase 2 — Agents, Experts & Governance | **Not started** | Architecture defined, implementation pending. |
| Phase 3 — Strategy, Knowledge & Work | **Not started** | Architecture defined, implementation pending. |
| Phase 4 — MCP Core | **Not started** | MCP architecture defined, implementation pending. |
| Phase 5 — Marketing, Media & Publishing | **Not started** | Existing CR8OR media/integration projects are external execution systems, not evidence that this phase is implemented in CR8OR Core. |
| Phase 6 — Finance & Business Operations | **Not started** | Architecture defined, implementation pending. |
| Phase 7 — Multi-Agent Business Operations | **Not started** | Future capability. |

### Reconciliation Rules

- The original product roadmap remains authoritative.
- Technical issue groupings are implementation slices, not replacement phases.
- Completed technical work must be mapped back to the appropriate product phase.
- Partially implemented capabilities must be explicitly identified.
- Existing CR8OR services do not automatically constitute completed CR8OR Core functionality.
- A capability is not complete merely because supporting infrastructure exists.
- The README must describe the actual repository state.

## Existing Implementation Milestones

The initial repository is intentionally being treated as a greenfield foundation.

Future technical milestones will be recorded here and mapped to the corresponding product phase.

### Implementation History Rules

Technical milestones are subordinate to the original product roadmap.

They must not be interpreted as a new phase numbering system unless the product roadmap itself is formally changed.

## Authoritative Specifications

The following documents will become authoritative as they are introduced:

- `docs/domain-model.md` — core entities and domain boundaries.
- `docs/architecture.md` — application and integration architecture.
- `docs/mcp.md` — MCP resources, tools, authentication and contracts.
- `docs/agents.md` — agent and expert model.
- `docs/governance.md` — permissions, approvals and auditability.
- `docs/integrations.md` — external service boundaries and contracts.
- `docs/implementation-decisions.md` — important architectural decisions.
- `docs/security.md` — security and authorization requirements.

### Specification Authority

When implementation conflicts with an approved specification:

1. Identify the conflict.
2. Determine whether the specification or implementation is incorrect.
3. Document the decision.
4. Update the authoritative specification when the product decision changes.
5. Implement the approved result.

No important architectural or product decision should exist only in an issue comment, chat message or someone's increasingly unreliable memory.

## Development Rules

1. Important architectural, product, workflow, policy, security and data decisions must be documented.
2. Do not silently contradict an approved specification.
3. Preserve historical truth and data integrity.
4. Authorization must be enforced server-side.
5. UI visibility must never be treated as a security boundary.
6. Sensitive mutations must use controlled application/domain workflows.
7. Database writes must respect domain invariants.
8. Every implementation issue must account for lint, static analysis and relevant automated tests.
9. New functionality must include appropriate regression coverage.
10. Existing functionality must not be broken to implement unrelated work.
11. Deliberate technical debt must be documented.
12. CI must be green before an implementation issue is considered complete.
13. A pull request is not complete merely because the code works locally.
14. Final implementation must satisfy the repository's lint, static-analysis and test requirements.
15. Historical records must remain interpretable according to the rules under which they were created.
16. MCP tools must not contain duplicated business logic.
17. AI agents must not bypass application authorization.
18. External integrations must not silently become authoritative sources of business state.
19. Idempotency must be considered for retried external operations.
20. Documentation must be updated when architecture or domain behavior changes.

## Quality Gates

The exact commands will be aligned with the repository's GitHub Actions configuration once CI is established.

**Lint**

`vendor/bin/pint --test`

**Static Analysis**

PHPStan configuration and command will be defined as part of the repository CI foundation.

**Tests**

`php artisan test`

**Frontend / Build**

The repository must validate its configured frontend build through CI where applicable.

**Full Validation**

The GitHub Actions workflow is the authoritative validation environment.

### Completion Requirement

An implementation issue is complete only when:

- lint passes;
- static analysis passes;
- relevant tests pass;
- the complete test suite passes where required;
- CI is green;
- the implementation conforms to applicable specifications;
- issue acceptance criteria are satisfied.

## Testing Strategy

**Unit Tests**

Test isolated domain logic, value objects, policies and application services where appropriate.

**Feature / Integration Tests**

Test complete application workflows and integration boundaries.

**Domain / Invariant Tests**

Test business rules and state-transition invariants.

**Authorization Tests**

Verify organization isolation, user permissions, agent permissions and sensitive-operation controls.

**MCP Tests**

Verify authentication, authorization, resource visibility, tool contracts, validation, application-service invocation and auditability.

**Regression Tests**

Every discovered regression should receive appropriate automated coverage where practical.

**CI**

GitHub Actions is the authoritative automated validation environment.

## Security & Authorization

### Authentication

Authenticated users and approved machine/AI clients must be identifiable before accessing protected CR8OR capabilities.

### Authorization

Authorization is enforced server-side through roles, permissions, policies and domain-specific capability checks.

### Tenancy / Data Isolation

Organizations provide the primary business data isolation boundary.

An agent acting for one organization must not obtain or mutate another organization's business state.

### Sensitive Operations

Operations such as financial mutations, publication, external spending, destructive changes, credential use and other high-impact actions may require additional authorization or human approval.

### Auditability

Important actions must record sufficient context to reconstruct:

- who or what initiated the action;
- which agent acted, where applicable;
- what capability was invoked;
- which authorization context applied;
- whether approval was required;
- what external execution occurred;
- what result was produced.

### Historical Integrity

Historical records must not be silently rewritten merely because current business rules have changed.

## Data & Historical Integrity

CR8OR must distinguish between mutable operational state and historical truth.

### Immutable Records

Candidates for immutable or append-only treatment include:

- financial transactions;
- approvals;
- audit entries;
- publication results;
- important agent decisions;
- external execution records.

### Versioned Records

Candidates for versioning include:

- enterprise context;
- strategies;
- agent instructions;
- policies;
- content;
- prompts;
- media assets;
- specifications.

### Snapshot Rules

Information that must remain historically accurate must be snapshotted rather than dynamically reconstructed from current mutable records.

### Historical Interpretation

Changes to methodology, pricing, permissions, agent instructions or business rules must not make previously recorded decisions or transactions unintelligible.

## User Interfaces

### Public Website

Public product surfaces may be introduced later.

### Authenticated Application

The primary authenticated application will use Filament for administrative and operational interfaces.

### Administration

Filament will provide interfaces for organizations, users, permissions, business configuration, agents, integrations and operational controls.

### Operational Interfaces

Operational dashboards will expose queues, approvals, jobs, agent executions, integrations, media workflows and business activity.

### UX Principles

- Interfaces should expose domain concepts rather than database implementation details.
- Sensitive actions must communicate authority and consequences clearly.
- Operational state should be visible without requiring users to inspect logs.
- AI-generated work should be distinguishable from approved or executed work.
- Destructive or high-impact actions require deliberate interaction.

## Notifications & Background Processing

### Notifications

Notifications may be generated for:

- approvals;
- failed jobs;
- agent decisions;
- integration failures;
- publication events;
- financial events;
- operational alerts.

### Queues / Jobs

Long-running or asynchronous work should use Laravel queues/jobs where the work belongs to CR8OR.

External orchestration should be delegated to n8n when the workflow crosses service boundaries or requires multi-service coordination.

### Scheduled Tasks

Scheduled tasks will handle recurring operations such as:

- metric collection;
- reporting;
- synchronization;
- health checks;
- scheduled publications;
- maintenance.

### Failure Handling

Failures must be explicit, observable and retryable where appropriate.

External operations must consider idempotency, retries, timeouts and partial failure.

## Commerce

CR8OR Core is initially focused on business operations rather than its own commerce layer.

If commercial functionality is introduced later, it must remain separate from the authority model and must not allow commercial relationships to corrupt enterprise decisions, recommendations or governance.

## API & Integrations

### API

The application architecture should support explicit application services and API boundaries where external clients require them.

### MCP

MCP is a first-class AI-facing interface.

It exposes:

- authorized resources;
- controlled tools;
- operational prompts/workflows where appropriate.

### External Services

Expected integration boundaries include:

- n8n — workflow orchestration;
- Cloudflare R2 — canonical media/file storage;
- CR8OR Media — media rendering;
- Canva — human-assisted creative workflows;
- Postiz — social publishing;
- GitHub — software development workflows;
- AI model providers — reasoning and generation;
- other specialized execution services.

### Webhooks

Webhook processing must validate authenticity, handle retries safely and persist relevant external events.

### Integration Rules

All integrations should consider:

- authentication;
- authorization;
- secrets management;
- request validation;
- idempotency;
- retries;
- timeouts;
- failure states;
- external identifiers;
- auditability.

## Configuration

Important configuration must remain environment-specific and secrets must never be committed.

| Configuration | Purpose |
|---|---|
| `.env` | Local/runtime environment configuration |
| `config/` | Application configuration |
| `.github/workflows/` | CI and automated validation |
| `composer.json` | PHP dependencies and scripts |
| `package.json` | Frontend dependencies and scripts |
| `phpstan.neon*` | Static-analysis configuration, when introduced |
| `pint.json` | Formatting/lint configuration, when introduced |

## Repository Structure

```
cr8or/
├── app/
│   ├── Actions/
│   ├── Domain/
│   ├── Filament/
│   ├── Jobs/
│   ├── Models/
│   ├── Policies/
│   ├── Services/
│   └── ...
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/
├── resources/
├── routes/
├── tests/
├── .github/
│   ├── workflows/
│   └── AI_DEVELOPMENT_RULES.md
├── README.md
└── ...
```

The exact internal directory structure may evolve as bounded contexts are implemented. Domain boundaries must remain explicit even if the underlying Laravel organization changes.

## Development Workflow

### Issue Naming Convention

Every GitHub Issue, whether open or closed, must follow:

**Phase [PhaseNumber].[IssueNumber] - Issue Title**

Example:

**Phase 1.1 - Create Organization Model**

### Branching

Implementation branches use:

**[phase]-[issue]**

Example:

`1-1`

### Issue Workflow

1. Review the applicable specification.
2. Review issue acceptance criteria.
3. Inspect the current repository and CI configuration.
4. Create the implementation branch.
5. Implement the change.
6. Run lint.
7. Run static analysis.
8. Run relevant tests.
9. Run the complete validation required by the repository.
10. Fix failures.
11. Push the branch.
12. Wait for CI.
13. Diagnose and resolve CI failures.
14. Repeat validation after every correction.
15. Open or update the pull request.
16. Merge only after required checks are green.
17. Close the issue.
18. Update documentation/README when the repository state changes materially.

### Pull Requests

PR titles use:

**#[issue-number] implemented**

Example:

**#101 implemented**

### CI Requirement

A pull request must not be considered complete until the required CI checks are green.

## Documentation Rules

Documentation must be updated when implementation changes:

- architecture;
- domain behavior;
- business rules;
- agent behavior;
- MCP contracts;
- authorization;
- lifecycle behavior;
- public behavior;
- security;
- data integrity;
- integration contracts;
- operational procedures.

Documentation is part of the implementation, not decorative paperwork added after the code has escaped into the wild.

## Current Direction

The current product direction is to establish **CR8OR Core as the authoritative business operating layer for AI-assisted businesses**.

The immediate priority is:

**Phase 1 — Identity, Organizations & Enterprise Context**

### Immediate Objective

Begin implementation of the first product domain while preserving the verified Phase 0 foundation.

### Current Dependencies

- Laravel.
- Filament.
- PHP.
- Composer.
- Node/npm.
- GitHub Actions.
- MCP architecture and implementation approach.
- External CR8OR execution services as they are integrated.

### Explicitly Deferred

- Full business management functionality.
- Production agent execution.
- Full MCP tool/resource surface.
- Marketing/media workflows inside CR8OR Core.
- Financial operations.
- Multi-agent orchestration.

### Next Product Milestone

Phase 1 — Identity, Organizations & Enterprise Context, implemented on top of the verified Phase 0 foundation.

## License

Private project. License to be defined.

## Maintainers / Ownership

**CR8OR**

The repository is maintained as the core application for the CR8OR platform.

## Additional Documentation

Documentation will be added under `docs/` as each architectural area becomes authoritative.

- `docs/domain-model.md` — domain entities and boundaries.
- `docs/architecture.md` — system architecture.
- `docs/mcp.md` — MCP interface and contracts.
- `docs/agents.md` — agent and expert architecture.
- `docs/governance.md` — permissions, approvals and auditability.
- `docs/integrations.md` — external service contracts.
- `docs/implementation-decisions.md` — architectural decisions.
- `docs/security.md` — security requirements.