# CR8OR

CR8OR is an AI-native business operating platform that provides a persistent system of record for enterprises, together with a controlled MCP interface through which AI agents can understand enterprise context, make decisions, request actions, and execute approved workflows.

CR8OR is designed to separate **business state**, **AI reasoning**, **capability exposure**, and **external execution**. Laravel owns the authoritative business state and application rules; AI agents own reasoning and decisions; MCP exposes controlled capabilities to AI clients; specialized external services perform media, publishing, development, and other execution tasks. n8n is an optional future MCP-integrated automation capability, not CR8OR's primary orchestrator.

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

**Agents reason and decide → CR8OR authorizes and owns state/rules → MCP exposes governed capabilities → specialized services execute.**

Automation systems such as n8n may be connected later through MCP by an Automatiser Expert when a business workflow requires them.

Agent execution is intentionally split into reasoning and governed execution. The Agent runtime produces a Capability Request; CR8OR validates authority and approval requirements; the requested Capability resolves to an Operation; the Operation invokes the appropriate application/domain service; the resulting state and audit record remain authoritative in CR8OR.

## Product Principles

The project is governed by the following principles:

1. **Business state belongs to CR8OR** — the Laravel application is the canonical source of truth for operational business state.
2. **Agents own decisions, not database access** — AI agents interact through explicit application capabilities.
3. **Domain behavior is explicit** — important business operations are represented by controlled application/domain workflows rather than arbitrary model mutation.
4. **MCP is an interface, not a second application layer** — MCP translates AI requests into application services and must not contain duplicated business logic.
5. **Human authority remains explicit** — sensitive actions can require approval and must be auditable.
6. **External systems are execution boundaries** — media workers, publishers, GitHub and other services perform specialized work without becoming the source of truth. Automation platforms such as n8n are optional MCP-connected capabilities rather than the core orchestration layer.
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

The product is organized around explicit domain boundaries rather than an uncontrolled collection of CRUD records. The entities below describe the intended **target persistent CR8OR domain model**; they are not all implemented in the current repository. Runtime Capabilities and Operations are documented separately because they are executable contracts rather than generic CRUD records. Runtime components such as Agents and Experts are documented separately because they are executable classes rather than business-state entities.

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

CR8OR also maintains **AgentDescriptor** and **ExpertDescriptor** records as the platform runtime registry for those components. Descriptors identify the runtime class and expose persistent registry information, while the PHP runtime classes remain authoritative for their identity, description, responsibilities, capabilities, methodology and executable behavior. Enterprise-level governance is represented separately through **AgentAssignment** and **AgentPermission** records.

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

**Agents orchestrate. Experts reason. Capabilities define governed authority. Operations execute business operations. Application services enforce business behavior. Descriptors register runtime components. Models persist business state.**

Agents determine which expertise is required, coordinate one or more Experts, and combine their results. Experts provide domain-specific reasoning, determine required context, and request appropriate Capabilities. A Capability is a reusable, governed business authority. An Operation is the concrete executable business operation associated with a Capability. Application services implement and enforce the business behavior required by Operations against authoritative CR8OR state or approved external services.

### Capability governance boundary

Capability availability, authorization and approval are intentionally separate:

- **Availability:** an Agent or Expert runtime declares a Capability it can use. The same Capability may be reused by multiple Agents or Experts.
- **Permission:** an Agent assignment receives explicit server-side permission for that Capability within its organization and Enterprise scope. Declaring a Capability does not grant permission.
- **Approval:** a separate governance decision is required when the permission is approval-sensitive. Permission does not imply approval.

When an Expert is used through an Agent, CR8OR verifies that the Expert declares the Capability and that the Agent assignment is explicitly permitted to use it. MCP and non-MCP execution share this authorization boundary.

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
   State-change / async mechanisms
        (where applicable)
                            |
                            v
                 External execution
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
| Event mechanisms | Communicate meaningful state changes where concrete event handling is implemented |
| Async job mechanisms | Perform asynchronous application work where the corresponding domain capability requires it |
| n8n | Optional future MCP-connected automation capability |
| External Workers | Perform specialized execution |
| Cloudflare R2 | Canonical generated-media and file storage |
| Filament | Administrative and operational application interface |

### Core Interaction Pattern

Every important governed operation should follow the conceptual pattern:

**Agent reasoning → Capability Request → Authorization → Approval, where required → Capability → Operation → Application / Domain Service → State Transition → Result / Audit**

The Capability is the reusable governed authority; the Operation is the executable business operation. An MCP Tool is only an interface through which the Capability may be requested.

Not every operation requires every step, but the boundaries must remain explicit.

Agent execution follows:

**Agent reasoning → Capability request → CR8OR authorization → Approval when required → Capability → Operation → Application / domain service → Authoritative state transition → Result / audit**

The Agent runtime produces and records governed Capability Requests. It does not implicitly execute arbitrary capabilities merely because an AI model requested them. Actual state-changing execution occurs through the CR8OR Capability → Operation boundary.

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

Tools are MCP-facing interface identifiers. A Tool does not itself grant authority. A governed state-changing Tool resolves to a CR8OR Capability and its executable Operation.

The canonical naming convention is:

- **Capability:** `marketing.content.create`
- **Operation:** `CreateContentItem`
- **Tool:** `create-content-item`

Capability identifiers use hierarchical dot notation, Operation classes use PascalCase, and MCP Tool names use kebab-case.

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

MCP Tools must resolve through the governed Capability boundary to an Operation and application/domain service rather than directly manipulating Eloquent models or database records. Tool registration does not grant authority.

### MCP Prompts / Workflows

Where appropriate, reusable operational workflows may be exposed for tasks such as:

- weekly marketing planning;
- financial review;
- business health review;
- product launch;
- content campaign planning;
- operational review.

The MCP surface must remain capability-oriented rather than exposing internal database structure. MCP Tools are interface contracts and must resolve through CR8OR Capabilities and Operations rather than becoming a second application layer.

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
- available Capabilities and their Operations;
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
    Capability
      |
      v
    Operation
      |
      v
    Application Service

The descriptor must never become a second source of truth for runtime behavior. Changing the implementation's authoritative metadata or behavior belongs in PHP code, tests and CI, not in an arbitrary editable glossary field.

### Platform Registry vs Enterprise Governance

The Agent/Expert registry has two distinct governance layers:

- **AgentDescriptor / ExpertDescriptor** define the platform-level runtime registry: which executable Agent/Expert classes exist, which runtime class they resolve to, and whether the registered runtime is enabled.
- **AgentAssignment / AgentPermission** define organization/enterprise-level authority: where a registered Agent may operate and which capabilities it may use.

Platform registry governance must not be confused with enterprise governance. The current implementation provides the descriptor registry and enterprise assignment/permission controls; a dedicated platform-authority boundary for administration of global descriptors should be established before the production Agent/Expert catalog expands.

An **Agent** is an orchestration component. It represents a broad operational domain and is responsible for understanding the request at a high level, selecting the appropriate Experts, coordinating their work, and combining their results.

Current runtime examples:

- CeoAgent
- MarketingAgent
- FinanceAgent
- ProductAgent
- OperationsAgent

An **Expert** is a domain-specialist component. It provides the methodology and reasoning required for a specific area of work, determines the context it needs, requests the Capabilities it requires, interprets Operation results, and produces a structured result.

Current runtime examples:

- MarketingExpert
- FinanceExpert
- ProductExpert
- OperationsExpert

Future/domain-specific Experts such as social media, copywriting, SEO, accounting, Laravel or UX specialists may be introduced when their corresponding responsibilities are implemented. They are illustrative roadmap examples, not claims about the current runtime catalog.

The runtime relationship is:

    Agent
      |
      v
    Expert
      |
      v
    Capability
      |
      v
    Operation
      |
      v
    Application Service
      |
      v
    Eloquent Models / External Services

For example:

    MarketingAgent
      |
      v
    SocialMediaExpert
      |
      +-- business.context.read
      +-- marketing.product.read
      +-- marketing.template.read
      +-- marketing.campaign.create
      +-- marketing.content_series.create
      +-- marketing.content.create
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
- request appropriate Capabilities;
- reason over authorized enterprise context;
- interpret Operation results;
- produce structured domain results.

**Capabilities**

- define reusable governed business authority;
- use stable hierarchical identifiers;
- do not grant authority merely by being declared or exposed;
- resolve to explicit Operations.

**Operations**

- represent concrete executable business operations;
- enforce application/domain rules through the appropriate service boundary;
- read or mutate authoritative business state;
- invoke approved external execution services;
- remain independently testable where practical.

**Application Services**

- implement or coordinate the business behavior required by Operations;
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

The operational lifecycle of an AI-assisted business operation is:

**Enterprise Context**
↓
**Agent Reasoning / Decision**
↓
**Plan, where applicable**
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
**Event, where an event is applicable to the operation**
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

CR8OR uses a governed operating methodology that keeps AI reasoning separate from authoritative business execution:

1. **Understand context** — resolve the authorized Enterprise, organization, business state and relevant Knowledge, Strategy and Work context.
2. **Reason within role** — an Agent coordinates the task and Experts provide specialized reasoning without gaining independent execution authority.
3. **Form a capability request** — reasoning produces a structured request for an application capability rather than a direct database operation.
4. **Authorize and approve** — CR8OR evaluates server-side permissions, organization/Enterprise scope and any required human approval before sensitive execution.
5. **Execute through application boundaries** — application/domain services own mutations and integration calls; MCP and external services do not duplicate business rules or become authoritative state stores.
6. **Record the outcome** — execution, decision, approval, delegation and external-result records preserve the context needed for historical interpretation.
7. **Report from authoritative state** — derived reports read CR8OR-owned records and do not replace the underlying business state.

This methodology is intentionally narrower than a generalized workflow engine, policy language, reporting platform or Agent-memory system. Those capabilities remain separately scoped and deferred where not implemented.### CR8OR Operating Model

CR8OR separates four responsibilities:

**Reason**

AI agents interpret enterprise context and determine what should happen.

**Control**

CR8OR applies authorization, domain rules, policies and approval requirements.

**Orchestrate**

CR8OR coordinates business workflows and owns authoritative business state. n8n is an optional future MCP-connected automation capability for external asynchronous or multi-service automation, not the primary CR8OR orchestration layer.

**Execute**

Specialized services perform rendering, generation, storage, publishing, development and other concrete operations.

This separation is authoritative for the initial architecture and should be documented further as implementation decisions are made.

## Capability / Operation / Tool Model

CR8OR uses three deliberately separate names for governed AI execution:

| Concept | Canonical form | Meaning |
|---|---|---|
| Capability | `marketing.content.create` | Reusable governed business authority |
| Operation | `CreateContentItem` | Concrete executable business operation |
| Tool | `create-content-item` | MCP-facing interface identifier |

A Tool does not grant authority. A Capability declaration does not grant permission. Authorization is evaluated server-side for the current actor, Agent/Expert context, organization and Enterprise scope, with approval applied independently where policy requires it.

Capabilities may be reused by multiple Agents or Experts. They are not owned exclusively by one runtime component. Operations provide the concrete execution boundary and must not be bypassed by direct Expert calls to arbitrary application services.

## Workflow Composition

A Workflow is a business-level composition of governed Capabilities and Operations that produces a business outcome. It is not itself an executable Operation, a runtime Execution attempt, or an asynchronous Job. The current repository models these concerns separately:

- **Workflow** — the business-level composition and its business context. A Workflow may contain multiple Jobs.
- **Operation** — the concrete executable business operation associated with a Capability. Operations remain the governed execution boundary for application behavior.
- **Job** — an asynchronous execution mechanism used when a workflow step needs background processing. A Job belongs to a Workflow and can be retried without becoming the business Workflow itself.
- **Execution** — a runtime attempt/result record for a Job, including historical context needed to interpret what happened.

The current implementation uses this model for concrete lifecycle-backed work such as media generation and rendering. It does not introduce a generic workflow engine. Reporting keeps workflow failures separate from failed operations/execution records so the concepts are not treated as interchangeable.

CR8OR should use the existing Agent, Expert, Capability and Operation primitives for real workflows rather than introducing a generic workflow engine prematurely. n8n remains an optional external automation/execution capability and never becomes authoritative CR8OR business state.

## Public Trust Model

CR8OR's authoritative record is the Laravel application database and its associated immutable/versioned records.

External services may provide execution results, but they do not become authoritative merely by holding a copy of the data.

AI-generated plans, recommendations, prompts and outputs are derived artifacts until accepted or executed through the appropriate CR8OR workflow. A Capability identifies governed authority; an Operation performs the concrete business operation; an MCP Tool is only the interface used to request it.

Important state changes must be attributable to:

- actor;
- agent, where applicable;
- capability;
- operation;
- MCP tool, where applicable;
- timestamp;
- authorization context;
- approval;
- external execution reference;
- resulting state.

## Verified Current State

The repository has completed **Phases 0 through 7**, from Foundation & Architecture through Multi-Agent Business Operations. Phases 0-7 have been implemented and audited within their defined boundaries. Phase 4 establishes the protected MCP boundary, authorized context resources, governed capability tools, provider-neutral AI execution infrastructure, Agent/Expert execution governance, and execution observability. Phase 7 adds governed Agent delegation, concrete business Agent/Expert runtimes, cross-Agent traceability, delegated approvals, business-level reporting and administration. Deferred capabilities remain explicitly identified in the roadmap. Phase 4 establishes the protected MCP boundary, authorized context resources, governed capability tools, provider-neutral AI execution infrastructure, Agent/Expert execution governance, and execution observability. It does not claim a complete catalog of business-specific Agents or Experts. Later product capabilities remain intentionally deferred to their roadmap phases.

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
- Protected `/mcp` MCP transport with Passport-backed authentication and OAuth discovery.
- Organization/enterprise-scoped MCP Enterprise, Strategy, Knowledge and Work resources.
- Governed MCP Work/Strategy mutation and approval-request tools backed by application services.
- Provider-neutral AI model execution through `App\AI\Contracts\ModelProvider`, including a real Laravel AI adapter and deterministic fake provider.
- Governed Agent/Expert execution against authorized Enterprise, Knowledge, Strategy and Work context with execution-time capability re-authorization.
- Correlated AgentExecution/AgentDecision/ApprovalRequest history with normalized failures, provider references and redacted observability.

### Intentionally Deferred

The following capabilities were deliberately deferred from the completed Phase 4 scope and belonged to later roadmap work:

- Concrete production business Agent catalogues.
- Concrete production business Expert catalogues.
- Agent-to-agent collaboration and multi-agent workflow orchestration.
- Broader capability catalogues, policy language and reporting.
- Generalized knowledge retrieval, indexing and vector infrastructure.
- AI planning and metric-calculation engines.
- Full workflow-engine semantics.
- Marketing, media and publishing domains inside CR8OR Core.
- Generalized forecasting and reporting-engine semantics.
- Cross-service business integrations.

These items are not missing Phase 4 implementation. Phase 4 provides the reusable runtime, governance, authorization, context and capability infrastructure on which later domain capabilities can be built.

Concrete Agents and Experts are introduced with the domains that require them rather than being treated as a generic Core catalog. Enterprise-specific runtime components likewise belong in the relevant enterprise/domain layer.

Existing external CR8OR services such as media, n8n, Canva or publishing infrastructure do not constitute completion of the corresponding CR8OR Core product phases.

### Audit Reconciliation

The Phase 1 audit confirmed the domain foundation, organization isolation, authorization and Filament administration. Phase 2 and Phase 3 audits subsequently verified the Agent/Expert governance and structured operating context. Phase 4.7 verified the MCP and governed AI execution boundary. Phase 6.7 verifies the Finance & Business Operations domain, administration, authorization and historical/reporting boundaries.

Current follow-up items are:

- keep authorization independent from UI visibility;
- continue tracking non-phase hardening separately from phase-completion audits;
- keep authoritative documentation and CI instructions aligned with the repository's actual commands and files.

These are ongoing hardening/documentation items, not evidence that the completed phases are absent. They should be tracked separately from the phase-completion status.

### Foundation Integrity

During Phase 0, a later change accidentally removed `.github/AI_DEVELOPMENT_RULES.md`. The Phase 0 audit detected the regression and restored the file before Phase 0 was closed. Required foundation files should therefore be protected by automated integrity validation rather than relying on human memory or review alone.

### Future Roadmap / Deferred Capabilities

Phase 7 is complete within its defined scope. The remaining roadmap consists of capabilities intentionally deferred from the completed phases, including generalized Knowledge Retrieval / AI Context Infrastructure, full workflow-engine semantics, policy-language infrastructure, generalized reporting/forecasting, Agent memory and broader cross-service integrations.

Generalized Knowledge Retrieval / AI Context Infrastructure should be introduced when the product requires retrieval beyond the currently implemented enterprise-scoped context assembly, with explicit authorization, indexing, ranking, semantic retrieval, context-budget and auditability boundaries. It is not a Phase 4 or Phase 7 completion gap.

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

**Status: Complete**

**Objective**

Establish the Agent and Expert runtime architecture, persistent descriptors, governance model, and administrative discovery interface. Phase 2 is the governance/control-plane phase; production execution and MCP integration were intentionally completed in Phase 4.

**Scope**

- Agent runtime contracts and PHP classes.
- Expert runtime contracts and PHP classes.
- `AgentDescriptor` and `ExpertDescriptor` persistent registry records.
- Runtime class registration and resolution.
- Agent and Expert metadata contracts.
- Read-only Filament Agent/Expert catalog and glossary.
- Agent instructions and runtime configuration.
- Capabilities and Operations.
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
- Capabilities define governed authority; Operations provide concrete executable business operations; application/domain services implement or coordinate their business behavior.
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

**Status: Complete**

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

**Verified implementation**

- Business knowledge is persistently represented with enterprise-scoped sources, documents, items, contexts, references, specifications and historical versions with version edits and deletion blocked by the authorization boundary.
- Strategy connects objectives, strategies, plans and initiatives and can reference existing Goals and KPIs.
- Projects, tasks, work items, milestones, dependencies and assignments provide the operational work model.
- Workflows, jobs and executions provide traceable CR8OR-owned execution history with explicit lifecycle and retry semantics.
- Decision records preserve decision-time actor and context as historical facts and remain distinct from AgentDecision and EnterpriseDecision.
- Filament administration is implemented with server-side organization/enterprise authorization and scoping.
- Full local validation and the implementation PR CI checks were verified during the Phase 3 audit.

**Deferred**

- Knowledge retrieval, indexing and vector infrastructure.
- AI planning or metric-calculation engines.
- Full workflow-engine semantics.
- Concrete provider execution and external task-management integrations.

### Phase 4 — MCP Core

**Status: Complete**

Phase 4 completes the **governed Agent/Expert execution infrastructure and capability boundaries**. It does not mean that CR8OR already contains a complete catalog of production business Agents or Experts. Concrete reusable platform Agents/Experts and enterprise-specific capabilities are introduced as their respective domains require them.

**Objective**

Expose CR8OR as a controlled AI operating interface and introduce the governed AI execution runtime for Agents and Experts.

**Scope**

- MCP server foundation.
- Authentication.
- Authorization.
- Resources.
- Tools.
- Agent and Expert execution runtime.
- Model/provider adapter layer.
- Agent context assembly and execution context management.
- Tool → Capability → Operation invocation through application/domain services.
- Agent and Expert execution lifecycle and traceability.
- Agent decisions and execution results.
- MCP auditability.
- Error, validation and provider-failure contracts.

**Architecture boundary**

Phase 4 is the verified implementation phase in which CR8OR first invokes AI models as part of its governed Agent/Expert runtime. The phase delivers the execution infrastructure and capability boundary, not a complete business-role catalog.

- The runtime may execute registered Agent/Expert classes, but the repository does not claim that every business role named in the target architecture has a concrete production implementation.
- Concrete Agents and Experts are domain capabilities introduced when their operational responsibilities are implemented.
- Enterprise-specific runtime components belong to the relevant enterprise/domain layer rather than automatically becoming part of CR8OR Core.

- Phase 2 defines and governs Agent/Expert runtime components, descriptors, assignments, permissions and historical execution/decision records.
- Phase 3 provides the structured enterprise context, strategy, knowledge and work state that execution can reason over.
- Phase 4 connects those foundations to an actual AI execution runtime.
- AI models reason within explicit Agent/Expert authority and request capabilities through governed Operations, application services and MCP Tools.
- Agents and Experts must not receive direct database access or bypass CR8OR authorization.
- Model/provider integration must remain replaceable so that changing an AI provider does not change authoritative business state.
- `AgentExecution` and related records provide persistent traceability around actual runtime execution rather than becoming an alternative execution engine.
- MCP translates AI requests into application capabilities and must not duplicate business logic.

**Completion Criteria**

- MCP clients can retrieve authorized enterprise context.
- MCP tools invoke application services.
- Authorized Agents and Experts can execute through the CR8OR runtime.
- AI model/provider calls are isolated behind explicit runtime contracts.
- Agent and Expert execution context is assembled from authorized CR8OR state.
- Tool → Capability → Operation calls are authorization-checked and auditable.
- MCP cannot bypass authorization.
- Execution failures and provider failures produce explicit, traceable outcomes.
- Agent execution and decision records preserve the required historical context.
- Business logic is not duplicated inside MCP.
- Relevant authorization, runtime, integration and regression tests pass.
- CI is green.

### Current Implementation Status

| Capability | Status |
|---|---|
| Core identity and organizations | Implemented |
| Enterprise context | Implemented |
| Enterprise operational domain | Implemented |
| Agent / Expert descriptors | Implemented |
| Agent assignments / permissions | Implemented |
| Agent execution governance | Implemented |
| Approval workflow | Implemented |
| Provider-neutral model interface | Implemented |
| MCP authentication / authorization | Implemented |
| MCP contextual resources | Implemented |
| Governed MCP capability tools | Implemented |
| Workflow / Job / Execution tracking | Implemented |
| Concrete business Agent catalog | Deferred to domain/product phases |
| Concrete business Expert catalog | Deferred to domain/product phases |
| Generalized knowledge retrieval | Deferred platform capability |
| Full workflow engine | Deferred |
| Cross-service business integrations | Future domain phases |

The status above distinguishes completed Phase 4 infrastructure from intentionally deferred product capabilities. “Deferred” does not indicate an incomplete Phase 4 implementation. Supporting infrastructure does not, by itself, make a business capability complete.

### Phase 5 — Marketing, Media & Publishing

**Status: Complete**

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
- Cloudflare R2 as the canonical media and file storage system.
- Publishing.
- Postiz publishing adapter/integration boundary.
- Canva design creation through a governed integration boundary with authorization, idempotency, correlation and external-resource tracking.

**Verified implementation:** Issues 65-70 implement and audit the Marketing, controlled content, media, publishing/Postiz, Canva integration boundary and Filament administration slices. CR8OR remains authoritative for business state, lifecycle, authorization, approval and historical records; external providers remain execution boundaries.

**Completion Criteria**

- Content can move through a controlled lifecycle.
- Asset generation is traceable.
- Render outputs are associated with source content.
- Published content is traceable to CR8OR state.
- External media and publishing services remain execution boundaries.

### Phase 6 — Finance & Business Operations

**Status: Complete**

**Objective**

Extend CR8OR into broader business operations and financial intelligence.

**Scope**

- Financial accounts.
- Transactions.
- Transaction categories.
- Statements and statement import records.
- Revenue.
- Expenses.
- Invoices.
- Budgets.
- Financial reporting.
- Operational metrics.
- Business health reporting.
- Financial reporting and business-health results derived from authoritative Phase 6 records.

**Verified Phase 6 implementation:** Issues 78-85 implement and audit the financial foundation, statement/import records, invoices/revenue/expenses, financial periods/budgets, derived reporting/business-health results, authorization-aware Agent financial context and Filament administration. Deferred accounting rules, automated reconciliation, provider integrations, payment processing, generalized forecasting and generalized reporting-engine semantics remain outside Phase 6.

**Verified Phase 6.3 implementation:** Invoices, Revenue and Expense are authoritative CR8OR records with Enterprise-safe relationships, historical invoice counterparty snapshots, fixed-precision monetary values and server-side organization authorization. Revenue and Expense reference authoritative accounts/transactions rather than duplicating ledger state; payment is not inferred from invoice lifecycle status.

**Completion Criteria**

- Financial records are auditable.
- Historical transactions remain interpretable.
- Financial operations respect authorization.
- Agents can access authorized financial context.
- Reports derive from authoritative records.

### Phase 7 — Multi-Agent Business Operations

**Status: Complete — Phase 7.1–7.7 implemented and audited**

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

**Phase 4 Completion Boundary**

Phase 4 delivers the governed AI/MCP execution infrastructure and capability boundaries. It is complete for that defined scope. Concrete business Agents and Experts remain domain capabilities introduced as their responsibilities are implemented, and generalized retrieval, full workflow-engine semantics and broader integrations remain later roadmap capabilities.

- Phase 4.1 protects the `/mcp` transport with Passport-backed authentication and establishes the MCP server boundary.
- Phase 4.2 exposes organization/enterprise-scoped Enterprise, Strategy, Knowledge and Work resources.
- Phase 4.3 exposes governed Work/Strategy mutation and approval-request tools through application services.
- Phase 4.4 isolates model-provider access behind `App\AI\Contracts\ModelProvider` with a real Laravel AI adapter and deterministic fake provider.
- Phase 4.5 executes authorized Agents against assembled Enterprise/Knowledge/Strategy/Work context, coordinates Experts, invokes the provider contract, re-authorizes capability requests and records execution/decision history.
- Phase 4.6 adds normalized errors, correlation, provider/external references, retry/idempotency coverage and redacted observability.
- All Phase 4 implementation PRs were merged with successful GitHub Actions CI runs.

Phase 4 does not introduce agent-to-agent collaboration, Finance, or a generalized workflow/policy engine. Marketing/Media/Publishing are implemented in Phase 5, and Finance & Business Operations are implemented in Phase 6. Agent-to-agent collaboration and broader workflow/policy capabilities remain later-phase capabilities. It also does not imply that enterprise-specific runtime components are part of CR8OR Core.

### Phase 7 Reporting Boundary

Phase 7.5 adds the minimum business-level multi-Agent report. It is Enterprise-scoped and derived from authoritative AgentExecution, AgentDecision, AgentDelegation, ApprovalRequest, Workflow and existing financial/business-health results. Report generation is read-only, failed operations remain failures, and historical Agent identity comes from the execution/delegation snapshots already maintained by CR8OR. A generalized reporting engine, forecasting and analytics platform remain deferred.

### Phase 7 Administration Boundary

Phase 7.6 exposes the implemented multi-Agent operational state through the existing Filament administration foundation. Agent delegation history and the business-level collaboration report are available through organization-scoped, server-authorized views. Existing Agent/Expert descriptors, executions, decisions, approvals and workflow records remain read-only where their historical semantics require it. Runtime Agent/Expert metadata remains authoritative in PHP and is displayed read-only. The administration surface is an operational view over CR8OR-owned state, not a second authorization or business-logic layer.

### Workflow / Job / Execution Boundary

The current Workflow, Job and Execution models provide CR8OR-owned lifecycle, idempotency and execution tracking. They are the authoritative execution-history layer for implemented workflows, not a generalized workflow engine. Full workflow-engine semantics remain deferred.

### Events and Jobs

Events and Jobs remain CR8OR application mechanisms for meaningful state-change communication and asynchronous application work. The current repository contains execution/lifecycle contracts and tracking infrastructure, while substantial concrete asynchronous workflows are introduced only where the corresponding domain capability requires them. n8n is not the primary CR8OR orchestration layer. It may be connected later through MCP by an Automatiser Expert when appropriate.

## MCP Surface: Current Implementation

The implemented MCP surface is intentionally narrower than the target architecture.

### Resources

- Enterprise Context
- Strategy Context
- Knowledge Context
- Work Context

### Tools

- Create Work Item
- Update Work Item
- Create Strategy
- Update Strategy
- Create Content Item
- Update Content Item
- Submit Content for Review
- Mark Content Publication Ready
- Publish Content
- Request Approval

These resources and tools are the currently implemented governed MCP capabilities. The broader examples in the target architecture are roadmap examples, not claims that those tools already exist.

### MCP Context Limitations

Current context assembly is enterprise-scoped and authorization-aware, but it does not yet provide generalized knowledge retrieval, ranking, indexing, semantic retrieval or token-budget optimization. Those capabilities remain part of the future Knowledge / AI context roadmap.

## Current Reconciliation

The repository has completed the Phase 1 implementation and audit, the verified Phase 2 Agent/Expert governance slice, the Phase 3 Strategy/Knowledge/Work implementation and audit, the Phase 4 MCP Core implementation and audit, the Phase 5 Marketing/Media/Publishing implementation and audit, and the Phase 6 Finance & Business Operations implementation and audit. Deferred capabilities remain explicitly identified rather than being represented as complete. Phases 4-6 should therefore be treated as closed, not as backlogs of missing MCP, AI-runtime, Marketing, Media, Publishing or Finance implementation work.

| Original phase | Current status | Reconciliation |
|---|---|---|
| Phase 0 — Foundation & Architecture | **Complete** | Foundation, CI, architecture specifications and development rules are implemented and verified. |
| Phase 1 — Identity, Organizations & Enterprise Context | **Complete** | Organization and membership identity, enterprise ownership, enterprise context, Phase 1 business records, authorization, historical decisions and Filament administration are implemented and validated. Audit follow-ups are tracked separately as hardening work. |
| Phase 2 — Agents, Experts & Governance | **Complete** | The 2.1–2.7 governance/control-plane implementation is complete and audited: runtime contracts, descriptors, assignments, permissions, execution/decision records, approvals and Filament governance administration are implemented. Phase 4 supplies the later production execution/provider integration; agent-to-agent workflows and broader policy/reporting capabilities remain deferred. |
| Phase 3 — Strategy, Knowledge & Work | **Complete** | Knowledge, strategy, objectives, plans, projects, tasks, assignments, workflows, jobs, executions and decision records are implemented, authorized, tested and documented. Retrieval/indexing, AI planning, full workflow-engine semantics, provider execution and external task-management integrations remain deferred. |
| Phase 4 — MCP Core | **Complete** | MCP authentication, authorized resources, governed tools, provider-neutral model execution, Agent/Expert runtime execution, approval enforcement, correlation, normalized errors and historical execution/decision contracts are implemented and audited. |
| Phase 5 — Marketing, Media & Publishing | **Complete** | Issues 65-70 implement and audit the Marketing foundation, governed content operations, media lifecycle, Postiz publishing, Canva integration boundary and administration UI. External systems remain execution boundaries and do not own CR8OR business state. |
| Phase 6 — Finance & Business Operations | **Complete** | Issues 78-85 implement and audit the financial foundation, statements/imports, invoices/revenue/expenses, periods/budgets, derived financial reporting/business health, authorization-aware Agent context and Filament administration. Deferred accounting rules, automated reconciliation, provider integrations, payment processing, generalized forecasting and generalized reporting-engine semantics remain outside Phase 6. |
| Phase 7 — Multi-Agent Business Operations | **Complete** | Phase 7.1 delegation foundation, Phase 7.2 core business Agent/Expert runtimes, Phase 7.3 governed cross-Agent workflow traceability, Phase 7.4 delegated approvals, Phase 7.5 business-level reporting, Phase 7.6 administration and Phase 7.7 final audit are implemented and audited within their defined boundaries. Deferred generalized workflow-engine, policy-language, reporting/forecasting and Agent-memory capabilities remain outside Phase 7. |

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

The repository is no longer a foundation-only greenfield baseline. Phases 0 through 7 have been implemented and audited within their defined boundaries. Phase 7 Multi-Agent Business Operations is complete, with delegation, core Agent/Expert runtimes, governed workflow traceability, delegated approvals, business-level reporting and administration implemented and audited within their defined boundaries. Deferred generalized workflow-engine, policy-language, reporting/forecasting and Agent-memory capabilities remain outside the completed scope.

Future technical milestones will be recorded here and mapped to the corresponding product phase.

### Core vs Enterprise-Specific Capabilities

CR8OR Core should provide reusable governance, execution, authorization, context and integration primitives. A business capability belongs in Core only when it is genuinely reusable across enterprises.

Enterprise-specific Agents, Experts and capabilities should live in the appropriate enterprise/domain layer and use the same CR8OR governance, execution and audit machinery. A component must not be added to Core merely because one enterprise requires it.

For example, an EdVenture-specific `CourseCoordinator` can be a valid enterprise/domain Agent or Expert without requiring every CR8OR enterprise to inherit that concept. The platform provides the runtime and governance boundary; the enterprise provides its domain-specific capabilities.

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

The following documents are also maintained as implementation-facing specifications and must remain reconciled with the codebase:

- `docs/integrations.md` — external service boundaries and contracts.
- `docs/implementation-decisions.md` — important architectural decisions.
- `docs/security.md` — security and authorization requirements.
- `docs/model-interface-boundaries.md` — human CRUD, controlled domain actions, and read-only operational/historical interface boundaries.

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

For supported application mutations, historical immutability and state-transition constraints are enforced at the Eloquent/application boundary. Direct database writes, raw SQL updates and bulk mutations outside the supported application mutation path are not treated as governed CR8OR operations and are outside these application-level guarantees.

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
## Current Agent / Expert Capability / Operation / Tool graph

The current runtime capability graph is exposed through governed MCP Tools. Runtime PHP classes remain authoritative for Capability metadata and Operation bindings; MCP is the transport and authorization coordination layer.

| Capability | Runtime consumer | Operation | Tool | Type |
| --- | --- | --- | --- |
| `agent.delegate` | CEO / Orchestration Agent | `DelegateAgent` | `delegate-agent` | state-changing delegation |
| `business.analysis` | Business Analysis Expert | `AnalyzeBusinessContext` | `analyze-business-context` | analysis |
| `marketing.plan` | Marketing Agent / Expert | `PlanMarketing` | `plan-marketing` | planning |
| `finance.report.generate` | Finance Agent / Expert | `GenerateFinancialReport` | `generate-financial-report` | controlled financial operation |
| `marketing.content.create` | Marketing Agent | `CreateContentItem` | `create-content-item` | state-changing |
| `marketing.content.update` | Marketing Agent | `UpdateContentItem` | `update-content-item` | state-changing |
| `marketing.content.review` | Marketing Agent | `SubmitContentForReview` | `submit-content-for-review` | lifecycle transition |
| `marketing.content.publication-ready` | Marketing Agent | `MarkContentPublicationReady` | `mark-content-publication-ready` | lifecycle transition |
| `strategy.create` / `strategy.update` | Product Agent | `CreateStrategy` / `UpdateStrategy` | `create-strategy` / `update-strategy` | state-changing |
| `work.item.create` / `work.item.update` | Product / Operations Agents | `CreateWorkItem` / `UpdateWorkItem` | `create-work-item` / `update-work-item` | state-changing |

Finance is intentionally not exposed as generic CRUD. The current Agent-facing Finance Operation is financial report generation through the existing reporting service; other Finance models remain governed by their existing policies until a dedicated domain Operation exists.