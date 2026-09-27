# CR8OR Agents & Experts

This document defines the Agent/Expert runtime architecture established in Phase 2 and completed for governed AI execution in Phase 4. It separates executable behavior from persistent business and governance state.

## Core Concepts

### Agent

An **Agent is an executable PHP runtime component** representing a broad operational role such as CEO, Strategy, Marketing, Finance, Product or Operations.

An Agent is responsible for:
- understanding and routing a high-level request;
- selecting and coordinating appropriate Experts;
- coordinating multi-expert work;
- operating within explicitly assigned authority and capabilities;
- combining Expert results into the requested outcome.

An Agent is **not an Eloquent model** and is not itself a persistent business-state record.

### Expert

An **Expert is an executable PHP runtime component** providing specialized reasoning and methodology within an Agent or workflow.

An Expert is responsible for:
- applying domain-specific methodology;
- determining required context;
- requesting appropriate Capabilities;
- reasoning over authorized enterprise context;
- producing structured domain results.

An Expert is **not an Eloquent model** and does not receive execution authority merely because it is invoked by an Agent.

Examples include Marketing, Business Analysis, Copywriting, SEO, Finance, Accounting, Product, Laravel and UX expertise.

### AgentDescriptor

An **AgentDescriptor is a persistent registry/governance record** identifying an Agent runtime class.

A descriptor may persist:
- a stable slug;
- the runtime class;
- enabled/disabled state;
- registry or governance configuration that genuinely belongs in persistence.

The descriptor is not the Agent implementation.

### ExpertDescriptor

An **ExpertDescriptor is a persistent registry/governance record** identifying an Expert runtime class.

The descriptor provides discovery and persistent registry information without becoming a second source of truth for the Expert's behavior.

### Canonical Agent Runtime Structure

Phase 8.1 establishes one canonical PHP structure for every Agent runtime. Each Agent implements an immutable AgentDefinition containing:

- identity (name);
- runtime description;
- responsibilities;
- authoritative instructions;
- Expert composition, represented by registered Expert slugs;
- required execution context;
- declared Capabilities.

The Agent base class exposes this definition through read-only runtime accessors and provides execute() as the canonical Agent execution entry point. coordinate() remains a compatibility alias for existing callers and delegates to execute().

The definition is runtime authority only. It does not create Agent permission, Capability permission or approval authority. Agent assignments, Agent permissions, authorization services and approval services remain authoritative for governance.

For the Marketing Agent, the runtime definition additionally identifies decision boundaries, expected outputs, a responsibility-to-Capability map, genuine Capability gaps and approval-sensitive Capabilities. These declarations describe the implemented runtime contract; they do not create permission or approval authority. Marketing execution history stores a deterministic definition version alongside the Agent runtime class so changes to these instructions and declarations remain identifiable when the existing execution snapshot is used for audit.

The runtime relationship is:

    AgentDescriptor
        |
        | runtime_class
        v
    Agent
        |
        | AgentDefinition
        +-- identity / metadata
        +-- instructions
        +-- Expert composition
        +-- required context
        +-- declared Capabilities
        |
        +-- execute()

Expert composition is declarative runtime metadata. It does not automatically grant or bypass Expert availability, Agent assignment, Capability permission or approval checks. Existing execution services remain responsible for resolving and governing actual Expert invocation.

### Canonical Expert Runtime Structure

Phase 8.2 establishes one canonical PHP structure for every Expert runtime. Each Expert implements an immutable ExpertDefinition containing:

- identity (name);
- runtime description;
- responsibilities;
- methodology;
- required execution context;
- declared Capabilities.

The Expert base class exposes this definition through read-only runtime accessors. analyze() remains the canonical Expert reasoning entry point and applies the Expert methodology to authorized context.

The definition is runtime authority only. It does not create Expert permission, Agent assignment authority, Capability permission or approval authority. Agent execution, Expert invocation and Capability authorization remain governed by the existing application services.

The runtime relationship is:

    ExpertDescriptor
        |
        | runtime_class
        v
    Expert
        |
        | ExpertDefinition
        +-- identity / metadata
        +-- responsibilities
        +-- methodology
        +-- required context
        +-- declared Capabilities
        |
        +-- analyze()

Expert capability declarations describe available expertise only. They do not grant execution authority. When an Expert is coordinated by an Agent, the existing execution boundary continues to verify the Expert declaration and the Agent assignment's explicit Capability permission before execution.

### Governed Expert Coordination

Phase 8.16 extends the Marketing Agent runtime with explicit Expert routing metadata. A Marketing execution can select a deterministic routing key, which resolves to the declared Expert set for that responsibility. The current Marketing catalog distinguishes:

- Marketing Expert: audience, positioning and campaign opportunity analysis;
- Strategy Expert: strategy alignment and strategic trade-offs;
- Copywriting Expert: messaging and content-language recommendations;
- SEO Expert: search-intent and discoverability analysis.

Expert routing is runtime metadata, not permission. Every routed Expert must still resolve to an enabled ExpertDescriptor, belong to the receiving Agent's declared Expert composition and pass the existing Agent assignment and Capability authorization boundary.

Expert context is specialized at invocation time. An Expert receives only the context categories declared by its ExpertDefinition::requiredContext(), plus the explicit invocation envelope. The parent Agent execution remains the source of the correlation identity and execution scope. Capability requests produced by an Expert use the canonical CapabilityRequest contract and inherit the parent Agent assignment, execution and correlation context.

Experts remain advisory reasoning components. They do not gain authority by being selected, and they cannot bypass the Agent → Capability → Operation → application-service boundary.


### Canonical Agent Execution Contract

Phase 8.3 establishes a provider-neutral, transport-neutral request boundary for Agent execution. `App\Data\AgentExecutionRequest` carries the actor, Agent assignment, prompt, authorized target context, selected Expert slugs, correlation identity and optional delegation context. Provider-specific options remain opaque to the contract and are consumed only by the infrastructure-facing execution service.

`AgentExecutionService` is the canonical application boundary. Its lifecycle is:

    AgentExecutionRequest
        |
        v
    Agent assignment + authorization
        |
        v
    authorized context
        |
        v
    Agent reasoning / Expert coordination
        |
        v
    Capability Request
        |
        +--> server-side authorization
        +--> approval when required
        +--> Capability -> Operation -> application/domain service
        |
        v
    AgentExecutionResult
        |
        v
    AgentExecution / AgentDecision / audit

Model-produced Capability Requests are represented by `App\Data\CapabilityRequest` as the canonical provider-neutral request contract. The contract carries the Capability identifier, target context, operation input payload, Agent assignment and execution identity, optional Expert identity, approval reference, correlation identity, idempotency key and delegated execution context. The request is scoped to its Agent execution and does not grant authority. `CapabilityRegistry` remains authoritative for Capability availability and Operation resolution, while `AgentCapabilityAuthorizer` remains authoritative for server-side permission and approval checks.

### Canonical Agent Context Contract

Phase 8.4 establishes `App\Data\AgentContext` as the provider-neutral current-execution context contract. Context is represented by explicit `AgentContextSection` values for Enterprise identity, Enterprise Context, Strategy, Work, Knowledge, Decisions, execution history and runtime instructions. Sections carry source, organization/Enterprise scope and optional relevance metadata.

`McpContextAssembler` remains the authorization-aware assembly boundary. It authorizes the Enterprise before creating the contract and each context source is serialized into bounded application data rather than exposing Eloquent models to the model provider. Existing Agent and Expert runtimes consume the contract through its compatibility payload serialization, so the execution boundary can adopt the canonical contract without changing provider interfaces.

`HistoricalContextAssembler` supplies the Decisions and execution-history sections when explicitly requested by an Agent. Decision history is Enterprise-scoped and can be narrowed by the execution target context; execution history is bounded and, when an Agent assignment is available, scoped to that assignment plus its relevant delegations and approvals. Historical identity fields are read from the persisted snapshots on the records, and failed executions/delegations remain represented with their recorded failure state. Persistent Agent memory remains separate from this context contract.

Current execution context and persistent Agent memory are separate concepts. `AgentContext` intentionally contains no memory section or memory persistence behavior. Memory may be introduced by a later governed context provider without changing the meaning of the current-execution contract.

Failures continue to use `ExecutionError` for normalized failure codes and human-readable reasons, while `ExecutionCorrelationService` provides the correlation identity. `AgentExecution` remains the authoritative persistent lifecycle record. No provider-specific request type, transport contract or generalized workflow engine is introduced.

### Runtime Metadata Authority

The runtime PHP class is authoritative for:
- identity and name;
- description and purpose;
- responsibilities;
- capabilities exposed by the implementation;
- required context;
- available Capabilities and their Operations;
- methodology;
- executable behavior.

Descriptors must not duplicate editable copies of these runtime properties merely to make them convenient to display.

Filament may resolve the runtime class from a descriptor and display runtime metadata read-only, creating a living technical glossary derived from the implementation.

### Capability

A **Capability** is a reusable, governed business authority identified by a stable hierarchical key such as `marketing.content.create`. Declaring or exposing a Capability does not itself grant authorization.

Capabilities:
- may be reused by multiple Agents or Experts;
- define the governed operation an actor may request;
- resolve to an explicit Operation;
- remain authoritative in runtime PHP rather than a duplicate editable persistence record.

### Capability availability, permission and approval

These are separate concepts and must not be collapsed into a single Expert or Agent declaration:

1. **Availability** means an Agent or Expert runtime declares or exposes a Capability as part of its implemented responsibility. The same Capability may be exposed by multiple Experts or Agents.
2. **Permission** is the server-side authorization granted to an Agent assignment for a Capability within an organization and applicable Enterprise scope. A runtime declaration never creates permission.
3. **Approval** is an independent governance decision required when the applicable permission is configured as approval-sensitive. A valid Capability permission does not satisfy a required approval.

For Agent-backed Expert execution, CR8OR verifies both sides of the boundary: the Expert must actually declare the requested Capability, and the Agent assignment must have an explicit permission for that Capability. Cross-organization and cross-Enterprise scope checks remain part of the same authorization path. MCP-backed Expert execution uses the same server-side authorization boundary.

### Operation

An **Operation** is the concrete executable business operation associated with a Capability, represented by an explicit PascalCase runtime class such as `CreateContentItem`.

Operations:
- perform the concrete business operation;
- enforce application and domain rules through the appropriate service boundary;
- read or mutate authoritative CR8OR state;
- invoke approved external execution services;
- remain deterministic and independently testable where practical.

### Tool

A **Tool** is an interface through which a Capability may be requested, such as an MCP Tool. Tool names use kebab-case, for example `create-content-item`. Tool visibility or registration never grants authority.

Canonical example:

- Capability: `marketing.content.create`
- Operation: `CreateContentItem`
- Tool: `create-content-item`

### Application / Domain Service

Application and domain services implement or coordinate the business behavior required by Operations. Experts and MCP handlers must not bypass the Capability boundary to invoke arbitrary services directly.

### Agent Instruction

Durable instructions governing an Agent's role, constraints, priorities and operating context. Instructions influence reasoning but are never a substitute for authorization.

### Permission

An explicit server-side authorization allowing an actor or Agent to perform or request a defined operation within an applicable organization and scope.

### Agent Assignment

A persistent relationship determining which Agent operates in a particular organization, enterprise or business context, together with the applicable scope and authority.

### Agent Execution

A persistent record of an Agent operation or attempt, including relevant actor/Agent identity, authorization context, status and external execution references where applicable.

### Agent Decision

A persistent record of a conclusion, recommendation or decision produced by an Agent. A generated decision is not automatically authoritative. Its business effect depends on the applicable acceptance, approval and execution workflow.

### Agent Memory / Context Reference

A persistent reference to durable context used by an Agent. It is not a replacement for authoritative domain records and must remain subject to authorization and historical-integrity rules.

## Runtime Relationship

The intended runtime boundary is:

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
    Application / Domain Service
        |
        v
    Eloquent Models / approved External Services

The descriptor layer registers and governs runtime components. The PHP classes execute reasoning and orchestration. Application/domain services own concrete business operations.

## Reasoning Authority vs Execution Authority

**Reasoning authority** determines what an Agent or Expert may analyze, recommend, plan or decide within its assigned role.

**Execution authority** determines which state-changing capabilities the actor may actually invoke.

These are intentionally separate. An Agent may recommend a financial action while lacking permission to execute it, or draft a publication while requiring human approval.

AI model capability, prompt instructions, Expert delegation or tool visibility must never override server-side authorization.

## Authority Flow

1. Establish the actor/Agent identity and organization context.
2. Load only authorized business and knowledge context.
3. Resolve the applicable Agent and runtime configuration.
4. Apply Agent instructions and assigned role.
5. Select and coordinate required Experts.
6. Produce a plan, recommendation or decision.
7. Request a named Capability when execution is required.
8. Re-evaluate server-side permission for the requested Capability.
9. Resolve the Capability to its Operation.
10. Require approval when policy demands it.
11. Execute the Operation through its application/domain service.
12. Persist execution and relevant result.
13. Record audit information and external execution references where applicable.

## Governance Rules

- Agents and Experts never receive authority from prompts alone.
- AgentDescriptor and ExpertDescriptor never become substitutes for server-side authorization.
- Tool access never implies permission.
- Delegation cannot be used to bypass an approval or permission boundary.
- An Expert cannot escalate its authority by being invoked by an Agent.
- AI-generated text, plans or decisions are derived artifacts until accepted or executed through the appropriate workflow.
- Direct database access from Agents and Experts is prohibited.
- Runtime components must not mutate Eloquent models directly when an application/domain capability should own the operation.
- Historical execution and decision records must not be replaced by current Agent memory or configuration.

### Agent-to-Agent Delegation Foundation

The runtime exposes governed Agent-to-Agent delegation through AgentDelegationService. A delegation request identifies the originating Agent assignment, target Agent descriptor slug, requested capability, actor, task context and correlation identifier. The service resolves the target through AgentDescriptor and AgentAssignment, requires both assignments to be enabled and within the same organization and Enterprise scope, and re-authorizes both the source agent.delegate capability and the target capability server-side. Approval requirements remain enforced through ApprovalRequestService. The Phase 7.3 traceability boundary persists each delegation with source/target Agent assignment references, Enterprise and organization scope, historical identity snapshots, actor, correlation and idempotency data. Delegation lifecycle is CR8OR-owned and reuses AgentExecutionService for the receiving Agent execution; the resulting AgentExecution references the delegation. Retries reuse the same delegation identity and cannot directly convert a failed delegation to succeeded.

### Cross-Domain Approval Coordination

Sensitive delegated capabilities reuse the existing ApprovalRequest and ApprovalRequestService boundary. Source-agent delegation approval and target-agent capability approval are bound to the exact AgentDelegation record, including the relevant actor, organization, Enterprise and target context. Approval decisions remain server-side and a target Agent cannot treat delegation itself as approval. When a sensitive capability is authorized for execution, the approval is consumed for that AgentExecution so the same approval cannot be replayed for another execution. Expiration, rejection, assignment/capability/context mismatch, cross-organization approval and terminal-state checks remain enforced.

## Persistence Boundary

Agents and Experts are executable runtime components, not persistent business entities.

Persistent information about them belongs in separate records such as:
- AgentDescriptor;
- ExpertDescriptor;
- Agent Instruction;
- Agent Permission;
- Agent Assignment;
- Agent Execution;
- Agent Decision;
- Approval;
- Audit Entry.

The runtime execution context is assembled from authorized Enterprise, Knowledge, Strategy and Work context by the governed Agent execution service. Model-provider access is now isolated behind the internal `App\AI\Contracts\ModelProvider` contract. The Laravel AI SDK is an infrastructure adapter only; Agent and Expert runtime classes must not depend on its provider-specific API. Provider credentials remain configuration-only and are never persisted as Agent or Expert business state.

## Verified Agent/Expert Runtime Boundary

The current repository implements and tests the Agent/Expert runtime contracts, descriptor registry, organization/enterprise-scoped assignments, capability permissions, governed Agent execution, execution and decision records, approval enforcement, MCP capability boundaries, and Filament governance administration. The governed execution service resolves enabled assignments, assembles authorized context, coordinates selected Experts, invokes the provider-neutral model contract, re-authorizes capability requests, and records execution outcomes. The runtime PHP classes remain authoritative for behavior and metadata.

## Core Business Roles

Phase 7.2 implements the five roadmap runtime Agents: CEO/Orchestration, Marketing, Finance, Product and Operations. Their metadata is authoritative in PHP and they remain non-persistent runtime components.

The minimum supporting Experts are Business Analysis, Marketing, Finance, Product and Operations. Expert descriptors register these runtime classes without granting execution authority.

## Current Capability / Operation / Tool Graph

The runtime PHP classes are authoritative for Agent and Expert capability declarations. The executable MCP Tool surface is reconciled as follows:

| Runtime | Capability | Operation | Tool | Context | Execution boundary |
| --- | --- | --- | --- | --- |
| CEO / Orchestration | `agent.delegate` | `DelegateAgent` | `delegate-agent` | source/target Agent scope | `AgentDelegationService` + `AgentExecutionService` |
| Marketing Agent / Marketing Expert | `marketing.plan` | `PlanMarketing` | `plan-marketing` | Enterprise, Strategy, Work, Knowledge, Decisions, execution history | `ExpertCapabilityService` + `MarketingExpert` |
| Finance Agent / Finance Expert | `finance.report.generate` | `GenerateFinancialReport` | `generate-financial-report` | Enterprise + financial period/account/category | `FinancialReportingService` |
| Business Analysis Expert | `business.analysis` | `analyze-business-context` | Enterprise, Strategy, Work, Financial | `ExpertCapabilityService` + `BusinessAnalysisExpert` |
| Marketing Agent | `marketing.content.create` | `create-content-item` | Enterprise + content context | `ContentItemService` |
| Marketing Agent | `marketing.content.update` | `update-content-item` | Enterprise + content item | `ContentItemService` |
| Marketing Agent | `marketing.content.review` | `submit-content-for-review` | Enterprise + content item | `ContentItemService` |
| Marketing Agent | `marketing.content.publication-ready` | `mark-content-publication-ready` | Enterprise + content item | governed content lifecycle service |
| Product Agent | `strategy.create` / `strategy.update` | `create-strategy` / `update-strategy` | Enterprise + objective/strategy | `StrategyService` |
| Product Agent | `work.item.create` / `work.item.update` | `create-work-item` / `update-work-item` | Enterprise + work | `WorkItemService` |
| Operations Agent | `work.item.create` / `work.item.update` | `create-work-item` / `update-work-item` | Enterprise + work | `WorkItemService` |

Finance was audited separately from the generic CRUD surface. The current Finance domain has governed policies and a concrete `FinancialReportingService`; therefore the Agent-facing `finance.report.generate` capability is exposed through `generate-financial-report`. Generic mutation of financial history is intentionally not exposed as a single Agent operation. Financial accounts, transactions, statements, invoices, expenses, revenue, periods, budgets and categories remain governed by their existing model/policy boundaries until a dedicated application/domain action exists for an Agent-facing use case.

Analysis and planning Operations are non-mutating at the business-state level. They assemble only authorized context, verify the requested Expert runtime is enabled and declares the capability, and invoke the Expert methodology. State-changing capabilities continue through their existing application/domain services and approval boundaries.

## Phase 7 Administration Boundary

Phase 7.6 exposes multi-Agent operational state through the existing Filament administration foundation. Delegation history and the derived collaboration report are organization-scoped and server-authorized. Existing execution, decision, approval and workflow records remain read-only where their historical semantics require it. Runtime Agent and Expert metadata remains code-authoritative and is displayed read-only; the administration layer does not grant runtime authority or replace application services.

## Deferred

The following remain intentionally deferred:
- generalized Agent governance/orchestration beyond the current execution service;
- a broader execution context catalogue beyond Enterprise, Knowledge, Strategy and Work;
- Agent memory implementation;
- generalized cross-Agent workflow orchestration beyond the governed delegation traceability boundary;
- broader capability catalogue and general policy language;
### Canonical Expert Invocation Contract

Phase 8.5 establishes `App\Data\ExpertInvocationRequest` and `App\Data\ExpertInvocationResult` with `ExpertInvocationService` as the canonical Agent-to-Expert application boundary. The request is explicitly scoped to the receiving Agent assignment and parent `AgentExecution` and carries the business objective, authorized context, expected reasoning output, target context and correlation identity.

Expert identity is runtime-derived from the enabled `ExpertDescriptor`. Invocation is denied unless the Expert is declared by the receiving Agent. The Expert's required context must be present in the authorized context supplied by the parent execution. Declared Capabilities are resolved through the existing `CapabilityRegistry` and re-authorized through the Agent assignment; approval requirements remain enforced by the existing authorization boundary.

The result distinguishes reasoning output, requested Capabilities, decisions, recommendations, execution metadata and normalized failure state. Expert invocation does not execute Capabilities, grant authority or create a second governance path. The parent `AgentExecution` remains the authoritative execution and audit record, with correlation identity preserved across the invocation boundary.