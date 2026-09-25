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

### Capability

A named business operation that an authorized actor may request. Capabilities are application-facing boundaries, not arbitrary model functions.

### Tool

An access mechanism through which a capability may be invoked, such as an MCP tool. A Tool does not grant authority by itself.

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
| Marketing Agent / Marketing Expert | `marketing.plan` | `PlanMarketing` | `plan-marketing` | Enterprise, Strategy, Knowledge | `ExpertCapabilityService` + `MarketingExpert` |
| Finance Agent / Finance Expert | `finance.report.generate` | `GenerateFinancialReport` | `generate-financial-report` | Enterprise + financial period/account/category | `FinancialReportingService` |
| Business Analysis Expert | `business.analysis` | `analyze-business-context` | Enterprise, Strategy, Work, Financial | `ExpertCapabilityService` + `BusinessAnalysisExpert` |
| Marketing Agent | `content.create` | `create-content-item` | Enterprise + content context | `ContentItemService` |
| Marketing Agent | `content.update` | `update-content-item` | Enterprise + content item | `ContentItemService` |
| Marketing Agent | `content.review` | `submit-content-for-review` | Enterprise + content item | `ContentItemService` |
| Marketing Agent | `content.publication_ready` | `mark-content-publication-ready` | Enterprise + content item | governed content lifecycle service |
| Product Agent | `strategy.create` / `strategy.update` | `create-strategy` / `update-strategy` | Enterprise + objective/strategy | `StrategyService` |
| Product Agent | `work.create` / `work.update` | `create-work-item` / `update-work-item` | Enterprise + work | `WorkItemService` |
| Operations Agent | `work.create` / `work.update` | `create-work-item` / `update-work-item` | Enterprise + work | `WorkItemService` |

Finance was audited separately from the generic CRUD surface. The current Finance domain has governed policies and a concrete `FinancialReportingService`; therefore the Agent-facing `finance.execute` capability is exposed through `generate-financial-report`. Generic mutation of financial history is intentionally not exposed as a single Agent operation. Financial accounts, transactions, statements, invoices, expenses, revenue, periods, budgets and categories remain governed by their existing model/policy boundaries until a dedicated application/domain action exists for an Agent-facing use case.

Analysis and planning actions are non-mutating at the business-state level. They assemble only authorized context, verify the requested Expert runtime is enabled and declares the capability, and invoke the Expert methodology. State-changing capabilities continue through their existing application/domain services and approval boundaries.

## Phase 7 Administration Boundary

Phase 7.6 exposes multi-Agent operational state through the existing Filament administration foundation. Delegation history and the derived collaboration report are organization-scoped and server-authorized. Existing execution, decision, approval and workflow records remain read-only where their historical semantics require it. Runtime Agent and Expert metadata remains code-authoritative and is displayed read-only; the administration layer does not grant runtime authority or replace application services.

## Deferred

The following remain intentionally deferred:
- generalized Agent governance/orchestration beyond the current execution service;
- a broader execution context catalogue beyond Enterprise, Knowledge, Strategy and Work;
- Agent memory implementation;
- generalized cross-Agent workflow orchestration beyond the governed delegation traceability boundary;
- broader capability catalogue and general policy language;