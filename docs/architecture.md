# CR8OR Architecture

## Unified execution model

The repository-wide verification report for this architecture is maintained in `docs/architecture/unified-execution-audit-2026-10-03.md`.

CR8OR separates orchestration, governed business execution, administration, and external-result reconciliation.

### Orchestrated business work

```
Agent → Expert → Capability → Operation → Application/Domain → State
Workflow → Stage → Expert → Capability → Operation → Application/Domain → State
```

Agents reason and orchestrate. Experts provide governed capability ownership within Agent execution. Workflows are persisted, deterministic orchestration. Capabilities are the governed business boundary. Operations perform the concrete business work.

### Direct business entry points

```
MCP business Tool → Capability → Operation → Application/Domain → State
Command Webhook → Capability → Operation → Application/Domain → State
```

Every direct business MCP Tool maps to exactly one Capability and exactly one Operation, and executes through the Capability boundary. A Capability may exist without an MCP Tool, and an internal Operation does not automatically become public.

### Explicit exceptions

```
Filament → Laravel application/model state
External Provider → Integration Webhook → IntegrationResultService → Reconciliation
```

Filament intentionally remains the native administrative interface and is not routed through MCP or the Capability boundary. External-result webhooks are reconciliation inputs, not business command entry points.

### Lifecycle MCP Tools

Agent/workflow lifecycle MCP Tools are orchestration controls rather than business Capability Tools. They use the explicit namespaces:

- `mcp_agent_*`
- `mcp_workflow_*`

They are not required to have a business Capability mapping.

## Governed business boundary

`CapabilityInvocationService` is the common execution substrate. It resolves the authoritative Capability registry, applies the relevant authorization and governance context, and invokes the mapped Operation. Entry points provide their legitimate context, but none owns a parallel business execution engine.

Authorization, approval, correlation, idempotency and normalized failure semantics remain explicit. Approval is independent from Expert ownership. An authenticated command Webhook credential is not itself an approval grant.

## Architecture invariants

- Agents do not own Capabilities directly. Agent authority flows through assigned Experts.
- Workflows do not call Operations by class name or invoke MCP business Tools as a shortcut.
- MCP business Tools do not contain independent business execution paths.
- Command Webhooks do not accept arbitrary Operation classes or model mutation instructions.
- Filament continues using normal Laravel/Eloquent application behavior.
- Integration-result webhooks continue through the integration reconciliation boundary.
- Deterministic Workflow execution does not require a ModelProvider.
- Workflows exist as persisted database state, not hardcoded runtime definitions.

## Execution proof

The repository includes cross-entry regression coverage proving a representative `work.item.update` Operation can be reached through direct MCP, Agent-backed Capability invocation, deterministic Workflow execution, and command Webhook execution while preserving Enterprise scope and authoritative state. Integration-result reconciliation and lifecycle MCP namespace separation are covered as explicit exceptions.

See `docs/architecture/capability-execution-contract.md` for the detailed request metadata, authorization, approval, idempotency and failure contract.