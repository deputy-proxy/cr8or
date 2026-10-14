# CR8OR Workflows

## Runtime model

A Workflow is a persisted, deterministic orchestration primitive for repeatable business work. Workflow definitions and stages live in the database. Runtime execution never depends on hardcoded workflow definitions.

```
Workflow → WorkflowVersion → WorkflowExecution → Stage → Expert → Capability → Operation → State
```

Each published stage declares its Expert, Capability, dependencies and input/output contracts. Publication validates the persisted graph. Published versions are immutable and historical executions remain bound to their exact version.

At runtime, the resolved Capability result is validated against the stage output contract before it is persisted in `WorkflowExecution.outputs` or exposed to downstream mappings. Required fields and declared types are checked for both JSON-schema-style contracts (`required` / `properties`) and legacy capability field maps (`field => type|required`). Empty output contracts remain intentionally opaque for dynamic capabilities. A contract failure fails the execution with stage/field details and does not persist the invalid stage output.

## Execution and governance

The Workflow runtime resolves the declared Capability through `CapabilityRegistry` and invokes the mapped Operation through the common Capability execution boundary. It does not invoke MCP business Tools, call Operations by arbitrary class name, or grant an Agent direct Capability authority.

Workflow orchestration remains deterministic and persisted. A ModelProvider is optional and is required only when the published WorkflowVersion explicitly permits model-backed stage input generation or when an Agent is performing model-driven reasoning.

## Generic and Enterprise-specific Workflows

CR8OR supports two Workflow ownership models:

- **Platform-owned generic Workflow:** `enterprise_specific=false`, `enterprise_id=null`. The reusable definition is shared by eligible Enterprises. Enterprise context is supplied at execution time and becomes execution-owned context.
- **Enterprise-specific Workflow:** `enterprise_specific=true`, with an owning Enterprise. Only that Enterprise's authorized users may execute or modify it.

Generic Workflows must not be duplicated per Enterprise merely to attach runtime context. A generic WorkflowVersion remains reusable and deterministic while each WorkflowExecution carries the actual Enterprise.

The canonical generic Marketing System Creation Workflow is `marketing.system.create`. It creates and verifies the marketing graph through existing governed Capabilities:

```
Strategy
  ↓
Audience
  ↓
Campaign
  ↓
Content Series
  ↓
Content Item
  ↓
Script
  ↓
Planned Asset
  ↓
Graph Verification
```

The Workflow uses deterministic mappings for upstream identifiers and the generic stage-input resolver for semantic fields. It does not create business records itself and does not call business MCP Tools.

## Execution input contract

Workflow execution accepts either the legacy flat input shape or the structured input shape. New callers should use the structured form:

```json
{
  "workflow": {
    "target_context": {},
    "objective": "...",
    "constraints": {}
  },
  "stages": {
    "<stage_key>": {
      "<input_key>": "<value>"
    }
  }
}
```

`workflow` contains execution-level input and is preserved in the durable WorkflowExecution context. `stages.<stage_key>` contains caller-supplied input for that specific stage. A stage never receives another stage's supplied input.

The effective input for a stage is resolved by the generic Workflow stage input resolver:

```
explicit input
    ↓
deterministic mappings
    ↓
stage defaults
    ↓
trusted execution context
    ↓
generated inputs (when explicitly declared and permitted)
    ↓
requested input (when explicitly declared)
    ↓
contract validation
    ↓
Capability invocation
```

Mapped values take precedence over supplied values for fields controlled by mappings. This prevents callers from overriding dependency-created identifiers or other values that the persisted WorkflowVersion owns. Defaults provide values only when neither supplied nor mapped input exists. Generated values are opt-in and are only resolved for fields explicitly declared as `generated` by the persisted stage contract.

The complete contract and provenance model is documented in [`docs/architecture/workflow-stage-input-resolution.md`](architecture/workflow-stage-input-resolution.md).

When a published WorkflowVersion contains a Capability input contract, structured stage input is rejected if it references an unknown stage or an undeclared input field. Existing legacy flat executions remain supported so published workflows can migrate without changing historical execution semantics.

Workflow stages remain deterministic in their orchestration and business execution. A stage may explicitly opt into model-backed input generation through its published execution policy. The model provider only resolves declared missing stage inputs; it does not invoke business Capabilities, Operations, MCP Tools, or persistence directly. Provider-free WorkflowVersions continue to reject generated-input stages.

## Continuation and idempotency

WorkflowExecution persists stage progress, outputs, correlation, idempotency and waiting state. Interactive continuation uses durable continuation tokens. A continuation may supply caller-owned `workflow` and `stages` input, allowing `requested` fields to be resolved without a ModelProvider. A continuation may invoke a ModelProvider only when the published stage contract explicitly requires generated input. Stale tokens fail closed. Retries reuse the durable WorkflowVersion/stage execution boundary.

A stage marked `repeatable` does not loop during a normal execution. When a failed WorkflowExecution is explicitly retried, previously completed repeatable stages may be re-entered once during that retry; non-repeatable completed stages reuse their persisted outputs. Re-entered stages receive a new attempt-specific idempotency key, while a failed stage without a completed output retains its original stage idempotency key.

## Domain-event continuation and recovery

Content approval and Workflow approval decisions create durable continuation-outbox records in the same database transaction as the authoritative state change. Laravel domain events are dispatched after commit and enqueue processing; a scheduled dispatcher also redelivers unprocessed outbox records so a process interruption between commit and queue dispatch does not lose the continuation request.

Outbox processing is idempotent. It delegates to `WorkflowExecutionService` and never invokes Operations, publishing adapters, or MCP business Tools directly. Workflow approval continuation verifies that the approved request belongs to the exact waiting execution and remains valid for its capability and target before consuming it through the normal Capability boundary.

When content is approved while a deterministic execution is failed at its review or content-approval stage, the runtime may reconcile those stages only when the immutable published WorkflowVersion declares the expected review and approval Capabilities, the execution's persisted `create_content.id` matches the approved content item, the item belongs to the same Enterprise, the stage dependencies are satisfied, and the lifecycle status proves the side effect occurred. Reconciliation outputs are validated against both the stage and Capability output contracts and include persisted reconciliation provenance. Repeatable stages, mismatched targets, ambiguous states, and unauthorized executions are not reconciled.

Approval-required Capabilities own creation of their approval requests. A nullable `approval_request_id` declared in a Capability input contract is therefore not treated as caller-required Workflow input; the Capability boundary creates the request and the execution waits for that exact request. Approval grants wake the same execution through the durable outbox. Content approval, publication readiness, and publication remain separate approval gates.

## Agent relationship

An Agent may select a persisted Workflow when work is known and repeatable. The Workflow remains the deterministic execution authority and can complete without an AgentExecution. Agent reasoning may inspect the persisted result, but does not rewrite it.

## MCP

MCP is an entry and continuation interface for Workflow lifecycle operations. Lifecycle MCP Tools remain separate from business Capability Tools and use the canonical action-oriented kebab-case identifiers. They control persisted orchestration state rather than becoming an alternative business execution path.

## Enterprise isolation

Enterprise-specific Workflows and their WorkflowVersions are Enterprise-scoped. Platform-owned generic Workflows have no Enterprise owner and may be executed only with an authorized Enterprise context. Every WorkflowExecution is Enterprise-owned, and Capability authorization is evaluated against that execution Enterprise. Published versions are immutable so later edits cannot silently change historical execution semantics.
## Work Dependencies vs Workflow Stage Dependencies

CR8OR contains two independent dependency concepts.

- **Work Dependency:** a directional `blocks` relationship between Project, Task, Task, or Milestone records. It belongs to the Work domain, is Enterprise-scoped, may be Project-scoped, and is validated for endpoint integrity, duplicates, and cycles. Work Dependencies may be included in authorized Agent Work context.
- **Workflow Stage Dependency:** a deterministic ordering/dependency relationship between stages in a Workflow definition. It is validated by the Workflow definition validator and participates in Workflow publication/execution.

The `Dependency` Eloquent model represents only the first concept. Workflow Stage dependencies must not be persisted or validated through the Work Dependency model.