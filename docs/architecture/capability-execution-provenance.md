# Capability execution provenance

CR8OR has three distinct Capability execution modes:

- **human**: direct user/application invocation governed by normal resource and Enterprise policies.
- **agent**: Agent-backed invocation governed by AgentAssignment, AgentExecution, Expert ownership, and applicable approval rules.
- **workflow**: deterministic Workflow invocation governed by the published WorkflowVersion, WorkflowStage Expert/Capability declaration, WorkflowExecution scope, and actor Enterprise access.

A deterministic Workflow does not create an AgentExecution merely to satisfy an Agent-specific domain invariant.

## Runtime contract

Every registered Capability has an explicit execution-mode classification in `CapabilityRegistry`.

Workflow definition publication rejects a Capability that does not support `workflow`, and `CapabilityInvocationService` repeats that check at runtime as defense in depth.

| Mode | Primary provenance | Typical authorization |
|---|---|---|
| human | actor + Enterprise/resource | Laravel Policy / EnterprisePolicy |
| agent | AgentAssignment + AgentExecution | AgentCapabilityAuthorizer + Policy |
| workflow | WorkflowExecution + WorkflowStage | Workflow definition + WorkflowExecution/Enterprise scope |

## Current capability matrix

| Capability | Human | Agent | Workflow |
|---|:---:|:---:|:---:|
| workflow.create | ✓ | ✓ | |
| workflow.update | ✓ | ✓ | |
| workflow.publish | ✓ | ✓ | |
| workflow.get | ✓ | ✓ | |
| workflow.discover | ✓ | ✓ | |
| workflow.execute | ✓ | ✓ | |
| workflow.inspect | ✓ | ✓ | |
| workflow.resume | ✓ | ✓ | |
| agent.continue | ✓ | ✓ | |
| agent.execute | ✓ | ✓ | |
| agent.delegate | ✓ | ✓ | |
| business.analysis | ✓ | ✓ | ✓ |
| finance.report.generate | ✓ | ✓ | ✓ |
| marketing.strategy.create | ✓ | ✓ | ✓ |
| marketing.strategy.section.define | ✓ | ✓ | ✓ |
| marketing.content.create | ✓ | ✓ | ✓ |
| marketing.asset.create | ✓ | ✓ | ✓ |
| marketing.graph.verify | ✓ | ✓ | ✓ |
| marketing.script.create | ✓ | ✓ | ✓ |
| marketing.content.update | ✓ | ✓ | ✓ |
| marketing.content.review | ✓ | ✓ | ✓ |
| marketing.content.publication-ready | ✓ | ✓ | |
| marketing.plan | ✓ | ✓ | ✓ |
| publication.publish | ✓ | ✓ | |
| strategy.create | ✓ | ✓ | ✓ |
| strategy.update | ✓ | ✓ | ✓ |
| work.item.create | ✓ | ✓ | ✓ |
| work.item.update | ✓ | ✓ | ✓ |
| enterprise.create | ✓ | ✓ | |
| approval.request | ✓ | ✓ | |
| marketing.audience.create | ✓ | ✓ | ✓ |
| marketing.audience.update | ✓ | ✓ | ✓ |
| marketing.audience.archive | ✓ | ✓ | ✓ |
| marketing.campaign.create | ✓ | ✓ | ✓ |
| marketing.campaign.update | ✓ | ✓ | ✓ |
| marketing.campaign.lifecycle | ✓ | ✓ | ✓ |
| marketing.channel.create | ✓ | ✓ | ✓ |
| marketing.channel.update | ✓ | ✓ | ✓ |
| marketing.channel.archive | ✓ | ✓ | ✓ |
| marketing.content-series.create | ✓ | ✓ | ✓ |
| marketing.content-series.update | ✓ | ✓ | ✓ |
| marketing.content-series.lifecycle | ✓ | ✓ | ✓ |
| enterprise.context.create | ✓ | ✓ | ✓ |
| enterprise.context.retrieve | ✓ | ✓ | ✓ |
| marketing.objective.create | ✓ | ✓ | ✓ |
| marketing.objective.update | ✓ | ✓ | ✓ |
| marketing.project.create | ✓ | ✓ | ✓ |
| marketing.project.update | ✓ | ✓ | ✓ |
| marketing.social-account.connect | ✓ | ✓ | |
| marketing.social-account.update | ✓ | ✓ | |
| marketing.social-account.disconnect | ✓ | ✓ | |
| marketing.strategy.archive | ✓ | ✓ | ✓ |
| knowledge.unit.archive | ✓ | ✓ | ✓ |
| knowledge.index.update | ✓ | ✓ | ✓ |
| knowledge.unit.update | ✓ | ✓ | ✓ |
| memory.create | ✓ | ✓ | |
| memory.update | ✓ | ✓ | |
| memory.archive | ✓ | ✓ | |
| memory.retrieve | ✓ | ✓ | |
| memory.record | ✓ | ✓ | |
| agent.assignment.create | ✓ | ✓ | |
| agent.assignment.update | ✓ | ✓ | |
| agent.assignment.transition | ✓ | ✓ | |
| knowledge.item.create | ✓ | ✓ | ✓ |
| knowledge.index.create | ✓ | ✓ | ✓ |
| knowledge.unit.create | ✓ | ✓ | ✓ |
| knowledge.retrieve | ✓ | ✓ | ✓ |

## Provenance rules

### Agent-backed execution

Agent provenance remains mandatory where the domain model requires it. Assignment and execution must belong to the same Enterprise, and execution must belong to the assignment.

Existing Agent approval and Expert authorization are not weakened by Workflow support.

### Workflow-backed execution

Workflow provenance is represented by WorkflowExecution and its current WorkflowStage. Workflow-created resources must not overload Agent provenance fields.

For planned assets, `assets.workflow_execution_id` records the governing Workflow execution. A script-planned asset must have either:

- Agent assignment + Agent execution provenance, or
- Workflow execution provenance.

The two provenance modes cannot be mixed.

### Human/direct execution

Human execution continues to use normal Laravel policies and resource authorization. Workflow context is never accepted as a substitute for direct-operation authorization.

## Workflow-incompatible capabilities

The following remain intentionally excluded from deterministic Workflow execution because their current contracts depend on Agent-specific lifecycle, approval, or external integration context:

- `marketing.content.publication-ready`
- `publication.publish`
- Agent lifecycle capabilities
- Workflow lifecycle capabilities
- Agent memory capabilities
- Agent assignment lifecycle capabilities
- external social-account connection/update/disconnect capabilities

A Workflow definition attempting to include one of these capabilities is rejected before publication.

This is an execution-mode contract, not a permanent prohibition. A capability can become Workflow-compatible only when its domain authorization, provenance, approval, persistence, and tests explicitly support Workflow execution.