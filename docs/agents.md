# CR8OR Agents

Agents are adaptive orchestration components. They reason over authorized context, select Experts, and request governed Capabilities. They do not execute business Operations directly.

## Runtime path

```
Agent → Expert → Capability → Operation → Application/Domain → State
```

The Agent selects or coordinates work. The Expert provides the governed capability ownership required for Agent execution. `ExpertInvocationService` validates Agent → Expert → Capability authority before execution reaches the shared Capability boundary.

Agent executions persist their lifecycle, correlation, idempotency and provenance. Interactive execution is provider-free when the request already contains governed Capability work. Autonomous execution may use the configured ModelProvider for reasoning, but the provider never becomes business-state authority.

## Capability authority

An Agent does not receive direct Capability authority. Capability availability comes from the assigned Expert/runtime definition, while permission and approval remain server-side governance decisions.

A requested Capability is represented by the canonical invocation/request contract and reaches `CapabilityInvocationService`. Operations are resolved through the Capability registry rather than by arbitrary class names supplied by an Agent.

## Delegation

Agent-to-Agent delegation remains an orchestration concern. Delegation records preserve source/target assignment, Enterprise scope, actor, parent execution, correlation and idempotency. A receiving Agent still follows the same Expert → Capability boundary.

## Deterministic Workflows

An Agent may select or delegate a persisted Workflow. The Workflow remains authoritative for its stage execution and can complete without creating an Agent execution. Agent reasoning does not rewrite a Workflow result.

## Memory and context

Agent context and persistent memory are derived, governed inputs. They never replace authoritative business state and never grant business authority.