# CR8OR

CR8OR is an AI-native business operating platform and persistent system of record for enterprises.

Its purpose is to let humans and AI agents operate a business through one governed application state and execution model. CR8OR stores authoritative business state, provides enterprise context, governs permissions and approvals, exposes controlled capabilities to AI clients, orchestrates repeatable workflows, records execution history, and connects specialized external services without surrendering ownership of business state.

CR8OR is deliberately **not** an AI model, an MCP-only wrapper, an n8n workflow database, or a collection of CRUD screens with an AI chatbot attached. It is the application and governance layer that sits between reasoning and business state.

The fundamental separation is:

```
AI reasons and decides
        ↓
CR8OR authorizes and governs
        ↓
Capability
        ↓
Operation
        ↓
Application / Domain behavior
        ↓
Authoritative business state
```

External systems are used where they are good at specialized execution:

```
CR8OR business state
        ↓
Integration / provider boundary
        ↓
External execution
        ↓
Result / reconciliation
        ↓
CR8OR authoritative state
```

The central architectural rule is simple:

> **AI may decide what should happen. CR8OR decides whether it is allowed to happen and owns what actually happened.**

---

## 1. What CR8OR Is For

CR8OR is intended to be the operating layer through which an enterprise can:

- define and preserve enterprise context;
- manage strategy, objectives and plans;
- manage projects, work items and operational execution;
- define Agents and specialized Experts;
- assign Agents to Enterprises;
- govern which Experts and Capabilities an Agent may use;
- execute business actions through explicit Operations;
- run deterministic, persisted Workflows;
- allow AI clients to interact through MCP;
- continue long-running interactive Agent work across multiple ChatGPT turns;
- run autonomous Agent work through workers and a ModelProvider;
- request and manage approvals;
- preserve correlation, idempotency and provenance;
- manage marketing, content, media and publishing;
- manage finance and business operations;
- retrieve enterprise Knowledge and context;
- connect external execution providers;
- reconcile provider results;
- expose operational state through Filament;
- preserve enough history to understand how important state was produced.

The platform is therefore both:

1. a **business application**, and
2. a **governed execution substrate for AI-driven work**.

These are not two separate systems. The same authoritative business state is used whether a change originated from a human, an Agent, a Workflow, an MCP client, or an authenticated command integration.

---

# 2. Core Architectural Principles

## 2.1 CR8OR owns authoritative business state

Laravel and the CR8OR application/domain layer own the authoritative state of the business.

Agents, MCP clients, external providers and automation systems do not become alternative sources of truth.

For example:

- a content item exists in CR8OR;
- a publication is recorded in CR8OR;
- a financial transaction is recorded in CR8OR;
- a Workflow execution is recorded in CR8OR;
- an Agent execution is recorded in CR8OR;
- an approval is recorded in CR8OR;
- an external job is represented by CR8OR integration state.

External systems may execute work, but their output is reconciled back into CR8OR.

## 2.2 Agents own reasoning, not application authority

An Agent can reason, plan, select Experts, request Capabilities and coordinate work.

An Agent cannot:

- arbitrarily mutate Eloquent models;
- choose an arbitrary Operation class;
- bypass authorization;
- bypass Enterprise isolation;
- treat an MCP Tool as implicit authority;
- create its own alternative execution path.

The server remains authoritative.

## 2.3 Experts provide governed specialization

An Expert represents specialized reasoning and capability ownership within an Agent execution.

Examples include:

- Marketing Expert;
- Copywriting Expert;
- SEO Expert;
- Finance Expert;
- Accounting Expert;
- Product Expert;
- Laravel Expert;
- UX Expert.

The canonical Agent path is:

```
Agent
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
Application / Domain
  ↓
State
```

There is deliberately no direct:

```
Agent → Capability
```

authority path.

## 2.4 Capabilities are the business authority boundary

A Capability describes a governed business ability.

An Operation is the executable implementation associated with that Capability.

The runtime mapping is:

```
Capability → Operation
```

The Capability Registry is the authoritative runtime mapping for governed execution.

A Capability may exist without a public MCP Tool. An Operation does not automatically become publicly callable merely because it exists.

## 2.5 MCP is an interface, not a second application

MCP gives AI clients a controlled interface to CR8OR.

Business MCP Tools must not contain duplicated business logic. A business Tool resolves into the governed Capability boundary and ultimately the registered Operation.

The intended path is:

```
MCP business Tool
    ↓
Capability
    ↓
Operation
    ↓
Application / Domain
    ↓
State
```

MCP therefore exposes the application. It does not replace it.

## 2.6 Workflows are persisted data

Workflows are not hardcoded executable graphs hidden in PHP.

The persisted model is:

```
Workflow
  ↓
WorkflowVersion
  ↓
WorkflowExecution
  ↓
WorkflowStage
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
State
```

Published WorkflowVersions are immutable. Historical executions remain attached to the version against which they were executed.

## 2.7 Filament is a deliberate exception

Filament is the administrative and operational application interface.

It does not need to route every form submission through MCP or the Capability Registry. Filament uses normal Laravel application behavior, Eloquent models, policies and application services.

The architecture is therefore not:

```
Everything → MCP
```

It is:

```
AI-facing business execution → Capability boundary
Human application administration → Laravel / Filament
External result reconciliation → Integration boundary
```

Trying to make all three identical would create abstraction for abstraction's sake, which is one of software engineering's more reliable ways to manufacture problems.

## 2.8 Provider-result webhooks are not command entry points

A command webhook means:

```
External caller
  ↓
Authenticated command
  ↓
Capability
  ↓
Operation
```

A provider-result webhook means:

```
External provider
  ↓
Integration webhook
  ↓
IntegrationResultEnvelope
  ↓
IntegrationResultService
  ↓
Reconciliation
```

These are different concerns and must remain different.

---

# 3. The Complete Execution Architecture

CR8OR has four principal governed business execution entry points.

### Agent

```
Agent
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
Application / Domain
  ↓
State
```

### Deterministic Workflow

```
Workflow
  ↓
Stage
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
Application / Domain
  ↓
State
```

### Direct business MCP

```
MCP business Tool
  ↓
Capability
  ↓
Operation
  ↓
Application / Domain
  ↓
State
```

### Command Webhook

```
Authenticated command
  ↓
Enterprise resolution
  ↓
Capability allowlist
  ↓
Capability
  ↓
Operation
  ↓
Application / Domain
  ↓
State
```

All four converge on the same governed execution substrate:

```
CapabilityInvocationRequest
        ↓
CapabilityInvocationService
        ↓
CapabilityRegistry
        ↓
Operation
        ↓
Application / Domain
        ↓
Authoritative state
```

This convergence is one of the most important properties of CR8OR.

There should not be one business implementation for MCP, another for Agents, another for Workflows and another for webhooks.

Different entry points provide different legitimate context. They do not own different business engines.

---

# 4. Actors and Responsibilities

## Human

Humans remain the ultimate organizational authority where the enterprise requires human decision-making.

A human can:

- configure enterprise information;
- assign Agents;
- configure permissions;
- inspect execution;
- approve or reject sensitive actions;
- inspect Workflow state;
- correct application state through authorized interfaces;
- inspect integration results;
- review AI-generated work;
- initiate direct application actions.

## AI Agent

An Agent is an adaptive orchestrator.

It can:

- understand authorized context;
- reason about a task;
- select Experts;
- request Capabilities;
- coordinate multiple steps;
- delegate to another Agent where permitted;
- select a persisted Workflow;
- continue an existing execution.

It cannot bypass CR8OR governance.

## Expert

An Expert provides specialized reasoning and governed Capability ownership.

It can:

- define specialized responsibilities;
- identify required context;
- reason about domain-specific work;
- request Capabilities it is authorized to use;
- participate in Agent and Workflow execution.

## Workflow

A Workflow is deterministic orchestration for repeatable work.

It can:

- define ordered or dependency-based stages;
- bind stages to Experts and Capabilities;
- preserve execution state;
- pause for approval or continuation;
- resume using durable state;
- execute without a ModelProvider.

## System Administrator

The administrator manages:

- identity;
- Enterprise configuration;
- Agents and Experts;
- assignments;
- permissions;
- integrations;
- operational controls;
- platform configuration.

## External Provider

A provider performs specialized execution outside CR8OR.

Examples include:

- Canva;
- Postiz;
- Cloudflare R2;
- CR8OR Media;
- GitHub;
- model providers;
- other approved external services.

A provider is not authoritative for CR8OR business state.

---

# 5. Agent and Expert Runtime

Agents and Experts are executable PHP runtime components.

They are not ordinary Eloquent business models.

CR8OR uses persistent descriptors to register runtime components:

```
AgentDescriptor
      ↓ runtime_class
Agent PHP class
      ↓
ExpertDescriptor
      ↓ runtime_class
Expert PHP class
      ↓
Capability
      ↓
Operation
```

Descriptors are registry/governance records. The executable PHP class remains authoritative for runtime behavior.

## AgentAssignment

An Agent becomes useful to an Enterprise through an assignment.

An assignment provides the governance context in which the Agent can operate.

The platform can therefore distinguish:

- which Agent exists;
- which runtime class implements it;
- which Enterprise it is assigned to;
- which permissions apply;
- which Experts it can use;
- which execution is currently in progress.

## AgentExecution

An AgentExecution represents durable execution state.

It preserves the information required to understand and continue an Agent's work, including applicable:

- Enterprise;
- actor;
- assignment;
- execution state;
- correlation;
- idempotency;
- provenance;
- decisions;
- Capability requests;
- results;
- continuation state.

## Agent decisions

Agent reasoning and business execution are separate.

The model may decide:

> Create a campaign for the product launch.

CR8OR then determines:

1. which Agent is acting;
2. which Expert is responsible;
3. whether the Expert owns the requested Capability;
4. whether the Agent assignment permits it;
5. whether approval is required;
6. which Operation implements the Capability;
7. whether the requested target belongs to the Enterprise;
8. whether the request is valid and idempotent;
9. whether the Operation can execute.

The model never becomes the authority for these checks.

---

# 6. Interactive and Autonomous Agent Execution

CR8OR has two Agent execution modes.

## 6.1 Interactive execution

Interactive execution is designed for continuous ChatGPT-driven work.

Conceptually:

```
ChatGPT
  ↓ MCP
CR8OR
  ↓
Agent
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
Persisted state
  ↓
MCP continuation
  ↓
ChatGPT
  ↓
...
```

The important property is persistence.

A conversation does not need to hold the complete execution state in its context window. CR8OR stores durable execution state and returns a structured continuation.

A later continuation request is validated against:

- the Enterprise;
- the Agent execution;
- the expected step;
- the continuation state;
- idempotency;
- authorization.

Interactive continuation is provider-free. The next reasoning result can come from ChatGPT through MCP without CR8OR itself needing to invoke a ModelProvider.

## 6.2 Autonomous execution

Autonomous execution is worker-driven.

Conceptually:

```
Schedule / Event
  ↓
CR8OR
  ↓
Worker
  ↓
Agent
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
ModelProvider, when reasoning is required
  ↓
next step
```

The ModelProvider supplies reasoning. It does not become the authority for business state.

The two execution modes share:

- Agent governance;
- Expert ownership;
- Capability authorization;
- Operation resolution;
- approvals;
- persistence;
- correlation;
- idempotency;
- failure semantics.

They differ only in how reasoning is supplied and how execution progresses.

---

# 7. Delegation

Agents can delegate work to other Agents where the platform permits it.

The conceptual path is:

```
Source Agent
  ↓
Delegation
  ↓
Target Agent Assignment
  ↓
Target Agent
  ↓
Expert
  ↓
Capability
  ↓
Operation
```

Delegation is an orchestration concern.

A receiving Agent does not inherit arbitrary authority from the source Agent. Its own assignment, Expert ownership, permissions and Enterprise scope still apply.

Delegation records preserve relevant provenance such as:

- source assignment;
- target assignment;
- Enterprise;
- parent execution;
- requested Capability;
- actor;
- correlation;
- idempotency;
- approval context.

---

# 8. Capability Architecture

A Capability is a reusable governed business ability.

Examples include:

- `business.analysis`;
- `marketing.strategy.create`;
- `marketing.content.create`;
- `marketing.content.update`;
- `finance.report.generate`;
- `work.item.create`;
- `work.item.update`;
- `strategy.create`;
- `strategy.update`.

The exact registry is defined in code and is the runtime source of truth.

## Capability definition

A Capability definition associates the relevant execution contract, including:

- capability identifier;
- Operation;
- MCP Tool where publicly exposed;
- input contract;
- output contract;
- authorization requirements;
- approval requirements;
- category;
- tool class.

## Capability categories

The registry distinguishes at least:

- business;
- lifecycle;
- read/query surfaces.

Business Capabilities are governed by the business execution invariant.

Lifecycle capabilities are orchestration controls such as Agent and Workflow lifecycle actions. They are intentionally separate from business mutation Capabilities.

---

# 9. Operation Architecture

Operations are explicit executable business actions.

The mapping is:

```
Capability
   ↓
Operation
```

An Operation may call:

- application services;
- domain services;
- policies;
- repositories where appropriate;
- external integration boundaries;
- persistence mechanisms.

An Operation must not be treated as a generic arbitrary class endpoint.

Agents, Workflows, MCP clients and command webhooks resolve Operations through the Capability Registry rather than accepting arbitrary Operation class names from an external caller.

This prevents a caller from turning an application service container into an accidental remote-code-execution-shaped API.

---

# 10. CapabilityInvocationService

`CapabilityInvocationService` is the common business execution boundary.

Its conceptual responsibility is:

1. receive a governed CapabilityInvocationRequest;
2. establish execution context;
3. resolve the Capability;
4. resolve the authoritative Operation;
5. apply the applicable governance;
6. execute the Operation;
7. translate failures into the platform's normalized execution contract;
8. return the result;
9. preserve relevant provenance and execution metadata.

The service is not a replacement for authorization systems or domain policies. It is the common execution substrate through which the entry points converge.

---

# 11. Authorization and Governance

Authorization is server-side.

A client interface, AI model, Tool name or visible UI control does not grant authority.

## Agent authorization

The Agent path uses:

```
Agent Assignment
  ↓
Expert
  ↓
Capability ownership
  ↓
Authorization
  ↓
Capability execution
```

The Agent cannot simply invent a Capability.

## Enterprise isolation

Every business operation is evaluated in an Enterprise context where applicable.

Cross-Enterprise access must fail closed.

Enterprise context is not merely prompt text. It is a security and business boundary.

## Approval

Approval is separate from permission.

A user or Agent may have permission to request an action while the action may still require an explicit approval decision.

This distinction is important:

```
Permission ≠ Approval
```

Approval-sensitive operations preserve approval context through execution.

Command Webhooks cannot turn possession of a valid command credential into an implicit human approval.

---

# 12. Correlation, Idempotency and Failure

CR8OR treats execution metadata as part of the architecture rather than optional logging decoration.

## Correlation

Correlation identifiers allow related actions to be followed across:

- Agent executions;
- Workflow executions;
- Capability requests;
- Operations;
- jobs;
- integrations;
- external providers;
- webhooks.

## Idempotency

Different entry points have appropriate idempotency semantics.

Agent execution uses durable execution/step state.

Workflow execution uses published-version and stage execution identity.

Direct MCP requests can pass idempotency through the governed invocation request where supplied.

Command Webhooks persist a delivery record keyed to the authenticated credential and idempotency key.

Domain Operations retain responsibility for domain-specific idempotency where the domain requires it.

## Failure

Business execution uses the normalized CR8OR failure contract.

Failures are not converted into provider-specific business semantics.

Command Webhooks expose the same canonical execution failure concept through an HTTP envelope.

Integration failures remain integration failures and are reconciled through the integration boundary.

---

# 13. Workflows

A Workflow is a persisted deterministic orchestration primitive.

A Workflow definition contains stages and their relationships.

A stage identifies, among other things:

- Expert;
- Capability;
- dependencies;
- input/output contracts;
- execution context.

The published WorkflowVersion is immutable.

Runtime execution is therefore:

```
WorkflowVersion
  ↓
WorkflowExecution
  ↓
Stage
  ↓
Expert
  ↓
Capability
  ↓
Operation
```

## Workflow publication

Persisted workflow definitions are validated before publication.

Validation checks that the persisted graph is structurally valid and that declared execution relationships are compatible with the governed Capability model.

## Workflow execution

A Workflow does not:

- call an arbitrary Operation class;
- invoke a business MCP Tool as a shortcut;
- contain a hardcoded business execution graph;
- require a ModelProvider merely to execute deterministic work.

## Workflow continuation

A WorkflowExecution persists:

- stage progress;
- outputs;
- waiting state;
- correlation;
- idempotency;
- continuation state.

Continuation tokens fail closed when stale or invalid.

## Workflow and Agents

An Agent may select a persisted Workflow when work is known and repeatable.

The Workflow remains the deterministic execution authority.

The Agent does not rewrite the Workflow's result.

---

# 14. Lifecycle MCP Tools

Lifecycle MCP Tools control orchestration state rather than directly exposing business mutation authority.

They use explicit namespaces:

- `mcp_agent_*`
- `mcp_workflow_*`

Examples include operations for:

- creating or executing Agent lifecycle state;
- continuing Agent execution;
- delegating Agent work;
- creating Workflows;
- publishing Workflows;
- starting Workflow execution;
- inspecting Workflow execution;
- resuming Workflow execution.

Lifecycle Tools are intentionally different from business MCP Tools.

They may invoke lifecycle Operations directly where that is the appropriate orchestration boundary. The business MCP 1:1 invariant applies to business mutation Tools, not to every lifecycle control in the platform.

---

# 15. MCP Architecture

CR8OR exposes an MCP server for AI clients.

MCP provides two important kinds of interface:

1. contextual resources;
2. executable Tools.

## Resources

Resources expose authorized contextual information such as:

- Enterprise context;
- strategy;
- knowledge;
- work.

Resources are read-oriented context interfaces.

They do not grant business mutation authority.

## Business Tools

A business Tool should map to:

```
one Tool
  ↓
one Capability
  ↓
one Operation
```

The registry enforces uniqueness of:

- Capability identifier;
- Operation mapping;
- MCP Tool identifier;
- Tool class mapping.

A business Tool must execute through the Capability boundary.

## Read/query/admin Tools

Not every MCP Tool is a business mutation Capability.

The server may expose:

- read/query tools;
- context tools;
- administration tools;
- lifecycle tools;
- business mutation tools.

The important distinction is classification and governance, not forcing every MCP endpoint into the same abstraction.

---

# 16. Command Webhooks

Command Webhooks provide a machine-to-machine business entry point.

The path is:

```
Authenticated command
  ↓
HMAC / credential authentication
  ↓
Enterprise identity resolution
  ↓
Capability allowlist
  ↓
Idempotency / correlation
  ↓
CapabilityInvocationService
  ↓
Operation
  ↓
State
```

A command webhook cannot submit:

- an arbitrary Operation class;
- an arbitrary application service;
- arbitrary model mutation instructions.

The credential explicitly determines which Capabilities it may invoke.

Replay protection is implemented through durable command delivery state.

Approval-required Capabilities cannot be bypassed merely because the command is authenticated.

---

# 17. External Integrations

External integrations are specialized execution boundaries.

Examples include:

- Canva for creative work;
- Postiz for publishing;
- Cloudflare R2 for storage;
- CR8OR Media for media processing;
- GitHub for repository operations;
- ModelProviders for AI reasoning.

The integration architecture is:

```
CR8OR
  ↓
Provider-neutral integration contract
  ↓
Provider adapter
  ↓
External system
```

CR8OR owns:

- authorization;
- business state;
- correlation;
- idempotency;
- reconciliation;
- provenance.

The provider owns its own execution mechanics.

---

# 18. External-result Webhooks

Provider-originated results are handled separately from command Webhooks.

The path is:

```
External Provider
  ↓
Integration Webhook
  ↓
IntegrationResultEnvelope
  ↓
IntegrationResultService
  ↓
Integration Job / reconciliation
  ↓
CR8OR state
```

This boundary exists because a provider result is not a new arbitrary business command.

The reconciliation layer must:

- authenticate the provider;
- validate the payload;
- normalize provider-specific data;
- correlate it to the CR8OR operation;
- deduplicate delivery;
- handle delayed or out-of-order results;
- preserve provider identifiers;
- update authoritative integration state.

---

# 19. n8n

n8n is not CR8OR's primary orchestration engine and is not the authoritative business-state store.

It can be used as an external automation capability when appropriate.

The intended relationship is:

```
CR8OR
  ↓ MCP
n8n / automation capability
  ↓
External automation
  ↓
CR8OR / external systems
```

An n8n workflow must not become a hidden second CR8OR business engine whose state cannot be understood from CR8OR.

This allows n8n to be useful without making CR8OR dependent on its internal workflow representation.

---

# 20. Knowledge and Context

CR8OR maintains durable enterprise context separately from transient model context.

Knowledge may include:

- enterprise documents;
- specifications;
- references;
- decisions;
- structured knowledge items;
- indexed representations;
- provenance.

Agent context assembly is authorization-aware and Enterprise-scoped.

The model's context window is not the system of record.

A useful conceptual distinction is:

```
Knowledge = durable source material
Context = authorized material selected for a particular execution
Memory = durable Agent-specific recollection
Business State = authoritative domain state
```

These should not be collapsed into one generic "AI memory" table.

---

# 21. Agent Memory

Agent memory is governed context, not business authority.

Memory may help an Agent remember:

- prior interaction context;
- relevant conclusions;
- useful working information;
- durable Agent-specific knowledge.

Memory must never override authoritative business state.

For example:

- a remembered campaign status is not authoritative if the Campaign record says otherwise;
- a remembered permission is not authorization;
- a remembered approval is not an Approval record.

The authoritative application state always wins.

---

# 22. Business Domains

CR8OR is intended to provide reusable business primitives across several domains.

## Enterprise

Enterprise identity and context define the organization being operated.

Examples include:

- vision;
- mission;
- goals;
- KPIs;
- products;
- customers;
- partners;
- competitors;
- strategic decisions.

## Strategy

Strategy expresses desired direction and measurable outcomes.

Examples include:

- objectives;
- strategies;
- plans;
- initiatives;
- KPIs;
- strategic decisions.

## Work

Work represents operational execution.

Examples include:

- projects;
- tasks;
- work items;
- milestones;
- dependencies;
- assignments;
- executions.

## Marketing

Marketing is modeled as a domain rather than a pile of posts.

Examples include:

- marketing strategies;
- campaigns;
- audiences;
- channels;
- content series;
- content items;
- scripts;
- publications;
- metrics.

## Media

Media has an explicit lifecycle.

Examples include:

- assets;
- versions;
- generation requests;
- generation jobs;
- transformations;
- render requests;
- render jobs;
- outputs.

## Publishing

Publishing is separated from content creation.

Examples include:

- social accounts;
- publication schedules;
- publications;
- publishing jobs;
- provider results;
- engagement metrics.

## Finance

Finance is a governed business domain.

Examples include:

- financial accounts;
- transactions;
- categories;
- statements;
- invoices;
- expenses;
- revenue;
- budgets;
- financial periods;
- reports.

Finance should not be exposed as arbitrary generic CRUD to Agents.

## Governance

Governance records include:

- policies;
- approvals;
- approval requests;
- audit entries;
- decisions;
- exceptions;
- change records.

---

# 23. Marketing Example

A typical AI-assisted marketing process can look like:

```
Human asks for a product launch campaign
        ↓
Marketing Agent
        ↓
Marketing Expert
        ↓
Enterprise context + strategy + knowledge
        ↓
Plan campaign
        ↓
Create campaign
        ↓
Create content series
        ↓
Create content items
        ↓
Generate media requests
        ↓
External media execution
        ↓
CR8OR records assets/results
        ↓
Human approval, where required
        ↓
Publish
        ↓
External publisher
        ↓
Provider result
        ↓
CR8OR publication state
```

The important point is that the Agent does not own the campaign. CR8OR does.

---

# 24. Finance Example

A Finance Agent may be asked to produce a financial report.

The Agent reasons over authorized enterprise and financial context:

```
Finance Agent
  ↓
Finance Expert
  ↓
finance.report.generate
  ↓
GenerateFinancialReport
  ↓
Financial reporting service
  ↓
CR8OR financial state
  ↓
Report
```

A finance report is derived from authoritative financial records.

The report does not become the financial ledger.

---

# 25. Product / Work Example

A Product or Operations Agent may create and update Work Items.

The path is:

```
Agent
  ↓
Product / Operations Expert
  ↓
work.item.create / work.item.update
  ↓
CreateWorkItem / UpdateWorkItem
  ↓
Application service
  ↓
WorkItem state
```

The same business Operation can be reached through:

- Agent execution;
- deterministic Workflow;
- direct MCP;
- command Webhook.

That is the purpose of the common execution substrate.

---

# 26. Human Application and Filament

Filament is the operational interface for humans.

It can be used to:

- manage Enterprises;
- inspect context;
- manage Agent assignments;
- inspect Agent executions;
- manage Workflows;
- inspect Workflow executions;
- manage domain records;
- inspect approvals;
- inspect integrations;
- inspect generated media;
- manage business configuration;
- inspect operational state.

Filament is intentionally allowed to use normal Laravel application state and application services.

It is not an MCP client pretending to be a human.

This also means that the application remains usable if MCP is unavailable.

---

# 27. Persistence Model

CR8OR uses persistence for more than CRUD.

Persistence provides durable execution semantics.

Important persisted concepts include:

- Enterprise;
- Enterprise Context;
- AgentDescriptor;
- ExpertDescriptor;
- AgentAssignment;
- AgentExecution;
- Agent decisions;
- delegation records;
- Workflow;
- WorkflowVersion;
- WorkflowExecution;
- WorkflowStage;
- Capability-related provenance;
- ApprovalRequest;
- Approval;
- command delivery records;
- integration jobs;
- integration results;
- domain records;
- audit history.

Durability allows CR8OR to continue work across:

- chat turns;
- worker executions;
- process restarts;
- delayed provider results;
- approval waits;
- external failures;
- retries.

---

# 28. Historical Integrity

CR8OR distinguishes current mutable state from historical truth.

Where required, the platform uses:

- immutable records;
- versioned records;
- append-only records;
- execution history;
- snapshots;
- explicit state transitions.

Important historical information must not silently change because current configuration changed.

Examples include:

- published WorkflowVersions;
- execution records;
- approvals;
- financial transactions;
- external execution results;
- publication results;
- important Agent decisions.

---

# 29. Security Model

Security is enforced server-side.

## Authentication

Protected interfaces identify the caller before granting access.

## Authorization

Authorization considers the relevant:

- user;
- organization;
- Enterprise;
- Agent assignment;
- Expert;
- Capability;
- target resource;
- approval state;
- integration credential.

## Isolation

Enterprise data is scoped so that an Agent acting for one Enterprise cannot access another Enterprise's state merely by changing an identifier in a request.

## Secrets

External credentials are integration concerns and must not be exposed to AI reasoning as arbitrary raw secrets.

## Auditability

Important execution should be reconstructable:

```
who / what
  ↓
which Enterprise
  ↓
which Agent / Expert
  ↓
which Capability
  ↓
which Operation
  ↓
which approval
  ↓
which external execution
  ↓
which result
  ↓
which state change
```

---

# 30. Extending CR8OR

New business functionality should normally be added through the following sequence:

1. Define the business concept and authoritative state.
2. Define the application/domain behavior.
3. Implement an explicit Operation.
4. Define the governed Capability.
5. Register the Capability → Operation mapping.
6. Add authorization and approval requirements.
7. Add an MCP Tool only if AI-facing direct access is appropriate.
8. Add Agent/Expert usage only where the capability belongs to that runtime.
9. Add Workflow support only if the work is meaningfully repeatable and deterministic.
10. Add command Webhook exposure only when machine-to-machine command execution is justified.
11. Add integration boundaries when specialized external execution is required.
12. Add tests for the business invariant and all relevant entry points.
13. Update the applicable architecture/domain documentation.

The existence of an Operation does not imply that it should be exposed through every interface.

---

# 31. Choosing the Right Entry Point

Use **Filament** when a human needs direct application administration or operational control.

Use a **business MCP Tool** when an AI client needs a direct governed business action.

Use an **Agent** when the work requires adaptive reasoning, Expert selection, context interpretation or delegation.

Use a **Workflow** when the process is known, repeatable and should execute deterministically from persisted configuration.

Use a **Command Webhook** when an authenticated external system needs to initiate a specific governed business capability.

Use an **Integration Webhook** when an external provider is returning the result of work CR8OR previously requested.

Use an **external provider** when specialized infrastructure already exists for the execution itself.

This distinction prevents every problem from becoming "add another Agent," which is how perfectly ordinary CRUD systems acquire 47 autonomous personalities.

---

# 32. What CR8OR Must Not Become

CR8OR should not evolve into:

### An arbitrary AI shell

AI must not directly manipulate the database.

### A second business application inside MCP

MCP Tools must not duplicate domain rules.

### A hidden n8n database

Business truth must remain in CR8OR.

### A hardcoded workflow engine

Persisted Workflow state must remain authoritative.

### An unrestricted Operation router

External callers must not select arbitrary PHP classes.

### A permission-free Agent framework

Agent assignments, Expert ownership, authorization and approvals remain server-side.

### A provider-owned business system

External providers execute specialized work. CR8OR retains business state.

### A universal abstraction layer

Not every human form, provider callback or lifecycle action needs to be forced through the same interface.

---

# 33. Testing and Architectural Enforcement

The architecture is enforced by automated tests and runtime guardrails.

Important classes of tests include:

- Capability Registry uniqueness and resolution;
- business MCP Tool boundary enforcement;
- lifecycle Tool classification;
- Agent → Expert authorization;
- Agent execution;
- deterministic Workflow execution;
- Workflow publication validation;
- Workflow continuation;
- Command Webhook authentication;
- command allowlisting;
- Enterprise resolution;
- command idempotency and replay protection;
- approval rejection for unsupported command contexts;
- integration-result reconciliation;
- Knowledge and Memory resource execution;
- cross-entry business-effect convergence.

The repository also maintains architectural documentation and an explicit unified execution audit.

A representative business Operation should produce the same authoritative business effect regardless of whether it is reached through:

```
Agent
Workflow
Direct MCP
Command Webhook
```

The interface and provenance differ. The business Operation and authoritative state do not.

---

# 34. Development and Quality Rules

A change is not complete merely because the PHP compiles.

Changes should preserve:

- domain invariants;
- authorization;
- Enterprise isolation;
- idempotency;
- historical integrity;
- execution provenance;
- architecture boundaries.

Relevant code must pass:

- formatting/lint;
- static analysis;
- automated tests;
- CI.

Architecture changes must also update the relevant documentation.

The README describes the platform architecture and intended utilization. More specific documents provide detailed contracts for their subject areas.

---

# 35. Authoritative Architecture Documents

The README provides the high-level architecture and utilization model.

The detailed specifications include:

- `docs/architecture.md` — overall application and execution architecture;
- `docs/architecture/capability-execution-contract.md` — common Capability execution contract;
- `docs/agents.md` — Agent and Expert runtime;
- `docs/workflows.md` — persisted deterministic Workflow architecture;
- `docs/integrations.md` — integration and webhook boundaries;
- `docs/domain-model.md` — domain entities and boundaries;
- `docs/governance.md` — authorization, approval and audit governance;
- `docs/mcp.md` — MCP resources, Tools and contracts;
- `docs/security.md` — security requirements;
- `docs/model-interface-boundaries.md` — human CRUD, domain actions and operational interfaces;
- `docs/implementation-decisions.md` — important architectural decisions.

The README should remain free of implementation-phase history. When the implementation evolves, this document should be updated to describe the resulting architecture rather than becoming a changelog.

---

# 36. The Intended CR8OR Operating Model

The complete intended operating model can be summarized as follows.

## Humans

Humans define goals, exercise organizational authority, configure the system and approve sensitive work.

## Agents

Agents reason about what should happen.

## Experts

Experts provide specialized reasoning and governed Capability ownership.

## Capabilities

Capabilities define what CR8OR permits a runtime to request.

## Operations

Operations define what business action is actually performed.

## Application / Domain

Application and domain services enforce business rules.

## Persistence

CR8OR stores authoritative state and durable execution history.

## Workflows

Workflows make known repeatable work deterministic and persistent.

## MCP

MCP makes governed CR8OR capabilities available to AI clients.

## Command Webhooks

Command Webhooks make explicitly allowlisted capabilities available to authenticated machine callers.

## Integrations

Integrations connect CR8OR to specialized external execution systems.

## Provider Webhooks

Provider Webhooks return external execution results into CR8OR's reconciliation boundary.

## Filament

Filament provides the human administrative and operational interface.

## Model Providers

Model Providers supply reasoning when autonomous execution needs them. They do not own business state or authorization.

## n8n

n8n can provide optional automation capabilities through MCP. It is not the primary CR8OR orchestration or persistence layer.

---

# 37. Canonical Architecture

The architecture can finally be represented in one diagram:

```
                             HUMAN
                               |
                               v
                         +-----------+
                         | Filament  |
                         +-----+-----+
                               |
                               v
                       Laravel Application
                               |
                               v
                        Authoritative State
                               ^
                               |
             +-----------------+-----------------+
             |                                   |
             |                                   |
         AI / MCP                            Workflows
             |                                   |
             v                                   v
       +-----------+                      +-------------+
       | MCP Tools |                      | Workflow    |
       +-----+-----+                      | Execution   |
             |                            +------+------+
             |                                   |
             v                                   v
       Capability                         Stage → Expert
             |                                   |
             +-----------------+-----------------+
                               |
                               v
                          Capability
                               |
                               v
                           Operation
                               |
                               v
                    Application / Domain
                               |
                               v
                     Authoritative State
                               |
              +----------------+----------------+
              |                                 |
              v                                 v
        Integration Boundary               Audit / History
              |
              v
      External Provider / Worker
              |
              v
      Provider Result Webhook
              |
              v
      IntegrationResultService
              |
              v
       Reconciled CR8OR State


Agent-specific path:

Agent
  ↓
Expert
  ↓
Capability
  ↓
Operation
  ↓
Application / Domain
  ↓
State

Command path:

Authenticated Command
  ↓
Enterprise Identity
  ↓
Capability Allowlist
  ↓
Capability
  ↓
Operation
  ↓
State
```

The invariant across the system is:

> **There is one authoritative business state and one governed business execution substrate. Interfaces may differ. Reasoning modes may differ. External providers may differ. The business authority does not.**

That is the architecture CR8OR is designed to provide.
