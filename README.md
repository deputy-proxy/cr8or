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

The product is organized around explicit domain boundaries rather than an uncontrolled collection of CRUD records. The entities below describe the intended persistent CR8OR domain model; they are not all implemented in the current repository. Runtime components such as Agents and Experts are documented separately because they are executable classes rather than business-state entities.

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

### Agent Runtime & Governance

Agents and Experts are **runtime components implemented as PHP classes**. They are not themselves Eloquent models or persistent business entities.

CR8OR also maintains **AgentDescriptor** and **ExpertDescriptor** records as a registry and in-app glossary for those runtime components. Descriptors identify the runtime class and expose persistent registry/governance information, while the PHP runtime classes remain authoritative for their identity, description, responsibilities, capabilities, methodology and executable behavior.

- Agent PHP classes
- Expert PHP classes
- AgentDescriptor
- ExpertDescriptor
- Agent Instruction / configuration
- Capability
- Tool
- Agent Permission
- Agent Assignment
- Agent Execution
- Agent Decision
- Agent Memory / Context Reference

The descriptor layer must not duplicate authoritative runtime metadata. Filament can resolve the registered PHP class and display its metadata read-only, providing a living technical glossary derived from the actual implementation.

**Agents orchestrate. Experts specialize. Functions execute. Descriptors register and describe. Models persist business state.**

Agents determine which expertise is required, coordinate one or more Experts, and combine their results. Experts provide domain-specific reasoning, determine required context, select appropriate Functions, apply their methodology, and produce structured results. Functions and application/domain services perform concrete operations against authoritative CR8OR state or approved external services.

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

Agents and Experts are executable CR8OR runtime components implemented as PHP classes. Their persistent counterparts are **AgentDescriptor** and **ExpertDescriptor** records used for registration, discovery, glossary presentation and persistent governance.

### Descriptors

An **AgentDescriptor** identifies an Agent runtime class without becoming the Agent itself.

An **ExpertDescriptor** identifies an Expert runtime class without becoming the Expert itself.

Descriptors should remain deliberately small. They may persist information such as:

- slug;
- runtime class;
- enabled/disabled status;
- registry or governance configuration that genuinely belongs in persistence.

The runtime PHP class is authoritative for:

- name and identity;
- description and purpose;
- responsibilities;
- capabilities;
- required context;
- available Functions;
- methodology;
- executable behavior.

Filament should resolve the runtime class from the descriptor and display this information **read-only**. This creates an in-app glossary that is generated from the actual implementation rather than maintained as duplicated database content.

The architectural relationship is:

    AgentDescriptor
      |
      | runtime_class
      v
    Agent PHP class
      |
      v
    ExpertDescriptor
      |
      | runtime_class
      v
    Expert PHP class
      |
      v
    Functions / Application Services

The descriptor must never become a second source of truth for runtime behavior. Changing the implementation's authoritative metadata or behavior belongs in PHP code, tests and CI, not in an arbitrary editable glossary field.

An **Agent** is an orchestration component. It represents a broad operational domain and is responsible for understanding the request at a high level, selecting the appropriate Experts, coordinating their work, and combining their results.

Examples:

- MarketingAgent
- FinanceAgent
- ProductAgent
- OperationsAgent

An **Expert** is a domain-specialist component. It provides the methodology and reasoning required for a specific area of work, determines the context it needs, selects the Functions it requires, interprets their results, and produces a structured result.

Examples:

- SocialMediaExpert
- CopywritingExpert
- SEOExpert
- AccountingExpert
- ProductExpert
- LaravelExpert
- UXExpert

The runtime relationship is:

    Agent
      |
      v
    Expert
      |
      v
    Function / Application Service
      |
      v
    Eloquent Models / External Services

For example:

    MarketingAgent
      |
      v
    SocialMediaExpert
      |
      +-- GetBusiness
      +-- GetProducts
      +-- GetTemplates
      +-- CreateCampaign
      +-- CreateSeries
      +-- CreatePost
      |
      v
    CR8OR state / approved external services

### Runtime responsibilities

**Agents**

- understand and route the high-level request;
- select and coordinate Experts;
- coordinate multi-expert workflows;
- enforce the Agent's available authority and capability boundaries;
- combine Expert results into the requested outcome.

**Experts**

- apply domain-specific methodology;
- determine required context;
- invoke appropriate Functions or application services;
- reason over authorized enterprise context;
- produce structured domain results.

**Functions / Application Services**

- perform concrete operations;
- enforce application/domain rules;
- read or mutate authoritative business state;
- invoke approved external execution services;
- remain deterministic and independently testable where practical.

### Persistence boundary

Agents and Experts are not themselves Eloquent models. Their descriptors are persistent registry/catalog records, while execution and governance records remain separate persistent entities.

If CR8OR needs to persist information about an Agent or its operation, that persistence belongs to separate models such as:

- Agent Execution
- Agent Decision
- Agent Assignment
- Agent Permission
- Approval
- Audit Entry

This keeps executable behavior separate from persistent business state.

An Agent must never gain authority merely because an AI model can technically call a tool.

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

The repository has completed **Phase 0 — Foundation & Architecture** and **Phase 1 — Identity, Organizations & Enterprise Context**. The current codebase is a working Phase 1 foundation and is preparing to begin Phase 2.

### Implemented

- Laravel 13 application foundation.
- Filament 5 administration foundation.
- Repository conventions and issue-driven development workflow.
- GitHub Actions CI with PHP 8.4, Node 22, lint, PHPStan and automated tests.
- Core architecture, domain, governance, MCP and integration specifications.
- Repository-level AI development rules in `.github/AI_DEVELOPMENT_RULES.md`.
- Organization and membership identity.
- Server-side organization and enterprise authorization.
- Enterprise ownership and one-to-one enterprise context.
- Phase 1 enterprise records: products, customers, partners, goals, KPIs and enterprise decisions.
- Filament administration for the implemented Phase 1 domain.
- Historical enterprise decision actor identity and decision timestamps.

### Intentionally Not Yet Implemented

The following remain product-roadmap work rather than completed runtime functionality:

- Runtime Agents and Experts.
- AgentDescriptor and ExpertDescriptor registry/runtime resolution.
- Agent capabilities, authority and governance runtime.
- Strategy, knowledge and work domains.
- MCP server implementation and tool/resource runtime.
- Marketing, media and publishing domains inside CR8OR Core.
- Finance and business operations.
- Multi-agent orchestration.

Existing external CR8OR services such as media, n8n, Canva or publishing infrastructure do not constitute completion of the corresponding CR8OR Core product phases.

### Phase 1 Audit Reconciliation

The Phase 1 audit confirmed that the domain foundation, organization isolation, authorization and Filament administration are substantially implemented. The audit also identified documentation and boundary work that should be resolved before Phase 2 grows the Agent/MCP surface.

Current follow-up items are:

- reconcile the Enterprise Context Filament table with the actual model fields;
- explicitly define whether enterprise decisions are mutable, append-only or versioned;
- establish reusable application/domain capability boundaries before Agents and MCP can invoke business mutations;
- keep authorization independent from UI visibility;
- align all authoritative documentation and CI instructions with the repository's actual commands and files.

These are not evidence that Phase 1 is absent. They are hardening items identified during the Phase 1 audit and should be tracked as implementation work before or alongside the relevant Phase 2 boundary work.

### Foundation Integrity

During Phase 0, a later change accidentally removed `.github/AI_DEVELOPMENT_RULES.md`. The Phase 0 audit detected the regression and restored the file before Phase 0 was closed. Required foundation files should therefore be protected by automated integrity validation rather than relying on human memory or review alone.

### Immediate Priority

**Phase 2 — Agents, Experts & Governance**

The next implementation work should establish the Agent and Expert runtime and governance layer on top of the completed Phase 1 foundation, while preserving the Phase 1 authorization and historical-integrity boundaries.

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

**Status: Complete**

**Objective**

Create the authoritative organization, user, enterprise and enterprise-context foundation.

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
- Enterprise context can be created and maintained.
- Authorization is server-side.
- Core domain invariants are tested.
- Administrative interfaces are operational.
- Historical enterprise decisions preserve actor identity and decision time.

### Phase 2 — Agents, Experts & Governance

**Status: In progress**

**Objective**

Establish the Agent and Expert runtime architecture, persistent descriptors, governance model, and administrative discovery interface.

**Scope**

- Agent runtime contracts and PHP classes.
- Expert runtime contracts and PHP classes.
- `AgentDescriptor` and `ExpertDescriptor` persistent registry records.
- Runtime class registration and resolution.
- Agent and Expert metadata contracts.
- Read-only Filament Agent/Expert catalog and glossary.
- Agent instructions and runtime configuration.
- Capabilities and Functions.
- Agent permissions and authority.
- Agent assignments.
- Agent execution records.
- Agent decision records.
- Approval requirements.
- Audit records.
- Runtime metadata remains authoritative in PHP classes and is not duplicated as editable descriptor fields.

**Architecture boundary**

- Agents and Experts are executable PHP classes, not Eloquent models.
- AgentDescriptor and ExpertDescriptor records register runtime classes and support discovery, glossary presentation, and persistent governance.
- Runtime PHP classes are authoritative for identity, description, responsibilities, capabilities, required context, methodology, and executable behavior.
- Filament displays runtime metadata read-only rather than maintaining a second editable copy.
- Functions and application/domain services provide concrete capabilities.
- Eloquent models persist business state and governance/execution records.
- MCP exposes authorized capabilities to AI clients without duplicating business logic.
- Agents and Experts do not receive direct database access merely because they are AI runtime components.

**Completion Criteria**

- Agents and Experts have explicit runtime contracts.
- Runtime implementations are PHP classes, not Eloquent models.
- Descriptors can register and resolve runtime classes.
- Runtime classes expose authoritative metadata.
- Filament can display runtime metadata read-only.
- Descriptor records do not become a second source of truth for runtime behavior.
- Agent authority is explicitly governed.
- Agent and Expert executions and decisions are auditable.
- Relevant authorization and regression tests pass.
- CI is green.

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

The repository has completed the Phase 1 implementation and audit. Phase 0 and Phase 1 are complete at the product-roadmap level; the audit identified a small set of hardening and documentation tasks that should be resolved as the project moves into Phase 2.

| Original phase | Current status | Reconciliation |
|---|---|---|
| Phase 0 — Foundation & Architecture | **Complete** | Foundation, CI, architecture specifications and development rules are implemented and verified. |
| Phase 1 — Identity, Organizations & Enterprise Context | **Complete** | Organization and membership identity, enterprise ownership, enterprise context, Phase 1 business records, authorization, historical decisions and Filament administration are implemented and validated. Audit follow-ups are tracked separately as hardening work. |
| Phase 2 — Agents, Experts | Phase 2 — Agents, Experts & Governance | **Not started** | Runtime Agent/Expert components, descriptors, registry/glossary and governance implementation are pending. | Governance | **In progress** | Agent/Expert runtime contracts and descriptors are implemented; Agent assignments and capability permissions are now implemented, with the remaining Phase 2 governance work pending. |
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
- Audit findings must be resolved or explicitly accepted as technical debt before they become hidden architectural assumptions.

## Existing Implementation Milestones

The repository is no longer a foundation-only greenfield baseline. Phase 0 and Phase 1 have been implemented and audited.

Future technical milestones will be recorded here and mapped to the corresponding product phase.

### Implementation History Rules

Technical milestones are subordinate to the original product roadmap.

They must not be interpreted as a new phase numbering system unless the product roadmap itself is formally changed.

## Authoritative Specifications

The following documents are authoritative where they exist and are applicable to the current implementation:

- `docs/domain-model.md` — core entities and domain boundaries.
- `docs/architecture.md` — application and integration architecture.
- `docs/mcp.md` — MCP resources, tools, authentication and contracts.
- `docs/agents.md` — agent and expert runtime architecture, descriptors and governance.
- `docs/governance.md` — permissions, approvals and auditability.

The following documentation areas are planned or may require creation/reconciliation as their implementation becomes authoritative:

- `docs/integrations.md` — external service boundaries and contracts.
- `docs/implementation-decisions.md` — important architectural decisions.
- `docs/security.md` — security and authorization requirements.

The README remains the product roadmap and current-state reconciliation document. More specific technical specifications take precedence for their own subject areas, provided they do not silently contradict approved product decisions.

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

The repository's current CI contract is defined by `.github/workflows/tests.yml` and the Composer scripts in `composer.json`.

### Setup

`composer setup`

This installs PHP dependencies, prepares the environment, migrates the database, installs frontend dependencies and builds the frontend.

### Lint

`composer lint:check`

Equivalent to the configured Laravel Pint test command.

### Static Analysis

`composer types:check`

Runs PHPStan/Larastan using the repository's `phpstan.neon` configuration.

### Tests

`php artisan test`

The test suite is also included in `composer test` after lint and static analysis.

### Full Local Validation

`composer test`

This runs:

1. configuration clearing;
2. lint;
3. static analysis;
4. the full Laravel test suite.

### CI Validation

GitHub Actions runs:

`composer setup`

followed by:

`composer ci:check`

The `ci:check` script invokes the repository's complete test sequence. GitHub Actions is the authoritative validation environment.

> **Dependency-install note:** the current `composer setup` script uses `npm install`, while the repository development rules recommend reproducible dependency installation with `npm ci`. This should be reconciled deliberately rather than documented as if the two were already identical.

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

### Phase 1 Enterprise Decisions

Phase 1 enterprise decisions currently preserve the actor identity and decision timestamp at creation time. The substantive decision content remains mutable in the current implementation.

Before Agent and MCP workflows depend on enterprise decisions as durable historical evidence, CR8OR must explicitly choose and implement the required historical model for those records: mutable, append-only, versioned, or a combination with immutable decision events.

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
| `composer.json` | PHP dependencies and project validation scripts |
| `package.json` | Frontend dependencies and scripts |
| `phpstan.neon` | PHPStan/Larastan static-analysis configuration |
| `pint.json` | Laravel Pint formatting/lint configuration |

## Repository Structure

The repository currently contains the implemented Phase 0/Phase 1 Laravel structure. The following directories represent the current and planned organization of the codebase:

```
cr8or/
├── app/
│   ├── Actions/          # implemented application actions, where applicable
│   ├── Filament/         # implemented administrative UI
│   ├── Models/           # implemented Eloquent domain models
│   ├── Policies/         # implemented authorization policies
│   ├── Agents/           # planned Phase 2 runtime components
│   ├── Experts/          # planned Phase 2 runtime components
│   ├── Functions/        # planned capability/runtime boundary
│   ├── Domain/           # planned/expanding domain-layer organization
│   ├── Jobs/             # planned/expanding asynchronous application work
│   ├── Services/         # planned/expanding application services
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

The exact internal directory structure may evolve as bounded contexts are implemented. Planned Agent, Expert and Function directories represent the intended runtime separation and must not be interpreted as evidence that Phase 2 is already implemented. Domain boundaries must remain explicit even if the underlying Laravel organization changes.

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

**Phase 2 — Agents, Experts & Governance**

### Immediate Objective

Begin implementation of the Agent and Expert runtime and governance layer on top of the completed Phase 1 foundation.

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

Phase 2 — Agents, Experts & Governance, implemented on top of the completed Phase 1 foundation.

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