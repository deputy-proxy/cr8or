# CR8OR Agents & Experts

This document defines the Phase 2 runtime architecture for Agents and Experts and separates executable behavior from persistent business and governance state.

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
- selecting appropriate Functions or application services;
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
- available Functions;
- methodology;
- executable behavior.

Descriptors must not duplicate editable copies of these runtime properties merely to make them convenient to display.

Filament may resolve the runtime class from a descriptor and display runtime metadata read-only, creating a living technical glossary derived from the implementation.

### Function / Application Service

A **Function** is a controlled executable capability boundary. In implementation, this may be represented by a dedicated Function class or an application/domain service where that is the appropriate boundary.

Functions and application/domain services:
- perform concrete operations;
- enforce application and domain rules;
- read or mutate authoritative CR8OR state;
- invoke approved external execution services;
- remain deterministic and independently testable where practical.

They are not model-generated authority.

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
    Function / Application Service
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
7. Request a named capability when execution is required.
8. Re-evaluate server-side permissions for the requested capability.
9. Require approval when policy demands it.
10. Execute through an application/domain service or controlled Function.
11. Persist execution and relevant result.
12. Record audit information and external execution references where applicable.

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

The exact schema, lifecycle and provider abstraction are implementation concerns for Phase 2 and later phases.

## Deferred

The following remain intentionally deferred until their relevant implementation phase:
- final Agent and Expert PHP contracts;
- model-provider abstraction;
- execution context schema;
- Agent memory implementation;
- delegation protocol;
- agent-to-agent collaboration;
- detailed capability catalogue;
- final permission matrix;
- detailed approval policy;
- MCP runtime registry and transport implementation.
