# CR8OR Workflows

## Runtime model

A Workflow is a persisted, deterministic orchestration primitive for repeatable business work. Workflow definitions and stages live in the database. Runtime execution never depends on hardcoded workflow definitions.

```
Workflow → WorkflowVersion → WorkflowExecution → Stage → Expert → Capability → Operation → State
```

Each published stage declares its Expert, Capability, dependencies and input/output contracts. Publication validates the persisted graph. Published versions are immutable and historical executions remain bound to their exact version.

## Execution and governance

The Workflow runtime resolves the declared Capability through `CapabilityRegistry` and invokes the mapped Operation through the common Capability execution boundary. It does not invoke MCP business Tools, call Operations by arbitrary class name, or grant an Agent direct Capability authority.

Deterministic Workflow execution is provider-free. A ModelProvider is required only when an Agent is actually performing model-driven reasoning.

## Continuation and idempotency

WorkflowExecution persists stage progress, outputs, correlation, idempotency and waiting state. Interactive continuation is provider-free and uses durable continuation tokens. Stale tokens fail closed. Retries reuse the durable WorkflowVersion/stage execution boundary.

## Agent relationship

An Agent may select a persisted Workflow when work is known and repeatable. The Workflow remains the deterministic execution authority and can complete without an AgentExecution. Agent reasoning may inspect the persisted result, but does not rewrite it.

## MCP

MCP is an entry and continuation interface for Workflow lifecycle operations. Lifecycle MCP Tools remain separate from business Capability Tools and use the `mcp_workflow_*` namespace. They control persisted orchestration state rather than becoming an alternative business execution path.

## Enterprise isolation

Workflow, WorkflowVersion and WorkflowExecution are Enterprise-scoped. Cross-Enterprise access fails closed. Published versions are immutable so later edits cannot silently change historical execution semantics.
## Work Dependencies vs Workflow Stage Dependencies

CR8OR contains two independent dependency concepts.

- **Work Dependency:** a directional `blocks` relationship between Project, Task, WorkItem, or Milestone records. It belongs to the Work domain, is Enterprise-scoped, may be Project-scoped, and is validated for endpoint integrity, duplicates, and cycles. Work Dependencies may be included in authorized Agent Work context.
- **Workflow Stage Dependency:** a deterministic ordering/dependency relationship between stages in a Workflow definition. It is validated by the Workflow definition validator and participates in Workflow publication/execution.

The `Dependency` Eloquent model represents only the first concept. Workflow Stage dependencies must not be persisted or validated through the Work Dependency model.
