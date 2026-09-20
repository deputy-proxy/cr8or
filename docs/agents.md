# CR8OR Agents & Experts

CR8OR agents are persistent operational roles that reason over authorized business context and request controlled capabilities. This document separates reasoning authority from execution authority.

## Core Concepts

### Agent
A persistent AI operational role with identity, purpose, instructions, capabilities, permissions, context access and execution history.

### Expert
A specialized reasoning role available to an agent or workflow. An expert contributes domain-specific reasoning but does not automatically receive additional execution authority.

### Agent Instruction
Durable instructions governing an agent's role, constraints, priorities and operating context. Instructions are configuration and business context, not a substitute for authorization.

### Capability
A named application operation that an authorized actor may request. Capabilities are business-facing boundaries, not arbitrary model functions.

### Tool
An interface through which a capability can be invoked, such as an MCP tool. A tool is an access mechanism, not a grant of authority.

### Permission
An explicit authorization allowing an actor to perform or request a defined operation within an applicable organization and scope.

### Agent Assignment
The relationship that determines which agent or expert operates in a particular organizational or business context.

### Execution
A recorded attempt to perform an operation, including relevant authorization and external execution references.

### Decision
A recorded conclusion or recommendation produced by an agent. A decision becomes operationally authoritative only through the applicable acceptance, approval or execution workflow.

## Reasoning Authority vs Execution Authority

**Reasoning authority** determines what an agent may analyze, recommend, plan or decide within its assigned role.

**Execution authority** determines which state-changing capabilities the agent may actually invoke.

These are intentionally separate. An agent may recommend a financial action while lacking permission to execute it, or draft a publication while requiring human approval. Model capability never overrides application permission.

## Authority Flow

1. Establish agent identity and organization context.
2. Load only authorized business and knowledge context.
3. Apply agent instructions and assigned role.
4. Produce a plan, recommendation or decision.
5. Request a named capability when execution is required.
6. Re-evaluate server-side permissions for the requested capability.
7. Require approval when policy demands it.
8. Execute through an application/domain service.
9. Persist execution and relevant result.
10. Record audit information.

## Experts

Examples include Marketing, Copywriting, SEO, Finance, Accounting, Product, Laravel and UX experts. An expert is not an implicit superuser. Any capability used by an expert remains subject to the same authorization and approval boundaries.

## Prohibited Shortcuts

- No direct database access from agents.
- No hidden authority encoded only in prompts.
- No capability invocation without server-side authorization.
- No treating generated text as an executed business decision.
- No bypassing approval through delegation to another agent.
- No replacing historical execution records with current agent memory or configuration.

## Deferred

Final model-provider configuration, memory implementation, delegation protocol and agent-to-agent collaboration are deferred until the relevant product phases.
