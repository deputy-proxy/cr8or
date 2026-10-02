# CR8OR Workflows

## Purpose

A Workflow is CR8OR's deterministic orchestration primitive for known, repeatable business work. It is complementary to Agent execution, not a replacement for Agent reasoning.

The two strategies are:

- Known work: Workflow → WorkflowVersion → WorkflowExecution → WorkflowStage → Expert → Capability → Operation
- Ambiguous work: Agent → select/create Workflow or governed capability path → Expert → Capability → Operation

A deterministic Workflow does not require an Agent, a ModelProvider, or a worker.

## Workflow vs WorkflowVersion

Workflow is the mutable process definition owned by an Enterprise. It identifies the process, its purpose, policy and current published version. Canonical Workflows may also declare a stable enterprise-scoped `canonical_key`, such as `marketing.strategy.create`. The canonical key is the deterministic identity used by provisioning and exact discovery; names and prompts are not canonical identifiers.

WorkflowVersion is an immutable release snapshot. A published version contains the exact stage definitions that future executions use. Publishing a new version never rewrites historical executions.

An execution always binds to one exact published WorkflowVersion. A canonical key is unique within an Enterprise when present, while existing non-canonical Workflows remain valid.

## WorkflowExecution vs AgentExecution

WorkflowExecution is the runtime record for deterministic business orchestration. It owns stage progress, input, context, outputs, idempotency and continuation state.

AgentExecution is the runtime record for model-driven reasoning and adaptive planning. An Agent may wrap a deterministic Workflow for provenance, but the Workflow does not depend on the AgentExecution.

A Workflow can therefore complete with zero AgentExecution records.

## WorkflowStage vs AgentExecutionStep

WorkflowStage is part of the durable Workflow definition. It declares sequence and dependencies, Expert ownership, governed Capability, input/output contracts, completion criteria and repeatability.

AgentExecutionStep records a reasoning iteration or a legacy stage-driven Agent step. When an Agent delegates a deterministic Workflow as a unit, it may contain a Workflow wrapper step with no workflow_stage_id; the linked WorkflowExecution remains the authoritative stage history.

## Governance

Every Workflow stage declares an Expert and Capability. The runtime resolves the Capability through CapabilityRegistry and invokes the mapped Operation through the application boundary.

Workflow runtime does not invoke MCP Tools as business logic, mutate Eloquent models directly as a shortcut, invoke Operations by class name outside the Capability boundary, grant an Agent direct Capability authority, or require a model provider to decide deterministic stage execution.

The governing path is Workflow → Expert → Capability → Operation → application/domain service → authoritative state.

## When to use a Workflow

Use a Workflow when the business process has known stages, dependencies and contracts can be expressed explicitly, the process must be resumable and auditable, deterministic execution is preferable to model-driven interpretation, or the same process can be reused across executions.

Use an Agent when the work requires interpretation, dynamic planning, exception handling, selection among possible Workflows, or adaptive decisions.

## Agent selection of Workflows

An Agent may receive a published Workflow as its execution target. The Agent delegates the complete deterministic execution boundary to WorkflowExecutionService.

After the Workflow reaches a terminal state, the Agent can inspect the persisted result and decide whether its overall objective is complete or whether another governed action is required. A Workflow failure remains a Workflow failure; Agent reasoning does not rewrite the Workflow result.

## Interactive Workflow continuation

Interactive Workflow execution is provider-free. The MCP/application interface starts the Workflow and receives a durable WorkflowExecution continuation contract.

When a stage waits for input or approval, the execution enters an explicit waiting state and rotates its continuation token. Resume requires the current token. A stale token fails closed.

Continuation never converts a deterministic Workflow into Agent/model execution.

## Autonomous Workflow execution

A deterministic Workflow may also be triggered by a schedule or event. A worker can advance that Workflow when asynchronous progression is appropriate.

The worker is an execution trigger, not the business-logic owner. Workflow state, authorization, idempotency and stage execution remain inside CR8OR.

Autonomous Workflow execution therefore does not imply ModelProvider usage. A ModelProvider is required only when an Agent is actually performing model-driven reasoning.

## MCP boundary

MCP is an entry point and continuation interface for Workflows.

MCP tools may create/discover/publish Workflows, start Workflow executions, inspect execution state and resume a waiting execution.

MCP tools do not own Workflow business logic. They resolve into the same application services used by internal callers.

## Enterprise isolation and history

Workflow, WorkflowVersion and WorkflowExecution are Enterprise-scoped. Cross-Enterprise access fails closed.

Published versions are immutable. Historical executions remain bound to the exact version they started from, so later Workflow changes cannot silently alter past execution semantics.
