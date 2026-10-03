# CR8OR Unified Execution Architecture Audit

**Issue:** #383  
**Audit date:** 2026-10-03  
**Repository:** `deputy-proxy/cr8or`

## Executive summary

The repository-wide audit was performed against the canonical execution model:

```
Agent → Expert → Capability → Operation → Application/Domain → State
Workflow → Stage → Expert → Capability → Operation → Application/Domain → State
Direct business MCP → Capability → Operation → Application/Domain → State
Command Webhook → Capability → Operation → Application/Domain → State
```

The deliberate exceptions remain:

```
Filament → native Laravel/Filament application state
Provider Webhook → IntegrationResultService → reconciliation
```

Current repository inventory:

| Surface | Count |
|---|---:|
| Agents | 7 |
| Experts | 10 |
| Operation classes | 82 |
| MCP Tool files | 130 |
| Concrete MCP Tool classes | 122 |
| CapabilityRegistry definitions | 65 |
| Business Capability definitions | 48 |
| Lifecycle Capability definitions | 12 |

## Findings

### P1: Agent service bypass

**Affected code:** `app/Services/ContentGenerationService.php`

The service previously performed Agent-driven content mutations by resolving an Operation directly from `CapabilityRegistry` and calling `execute()`.

**Remediation:** both content creation and revision now construct a `CapabilityInvocationRequest` and invoke `CapabilityInvocationService`, preserving Agent assignment, Agent execution, Expert provenance, Enterprise scope, correlation and idempotency.

### P1: Unmapped mutating resource Tools

The following mutating MCP Tools were not previously represented in the business Capability registry:

- `ArchiveKnowledgeUnitTool`
- `UpdateKnowledgeIndexTool`
- `UpdateKnowledgeUnitTool`
- `CreateMemoryTool`
- `UpdateMemoryTool`
- `ArchiveMemoryTool`

**Remediation:** six explicit Capability → Operation → Tool mappings were added:

- `knowledge.unit.archive`
- `knowledge.index.update`
- `knowledge.unit.update`
- `memory.create`
- `memory.update`
- `memory.archive`

Mapped Knowledge/Memory resource mutations now enter the canonical Capability invocation boundary.

### P2: Guardrail gap

The previous registry guardrail trusted several shared Tool base classes without checking whether a concrete business Tool could directly resolve its mapped Operation.

**Remediation:** the registry guardrail now checks the concrete Tool source for direct Operation execution and recognizes the governed resource base classes whose mapped execution delegates through `invokeCapability()`.

### P2: Documentation drift

The previous Operation/Capability/MCP audit contained execution-path annotations from an earlier implementation state.

**Remediation:** this report records the current audit and disposition. The existing inventory remains useful as the detailed surface inventory, while this document is authoritative for the post-#383 audit findings.

## Agent and Expert audit

Repository searches found no direct business Operation resolution or direct model mutation inside Agent or Expert runtime classes.

The governed path is:

```
Agent → Expert → CapabilityInvocationService → Operation
```

Expert invocation validates Agent → Expert → Capability ownership before execution.

## Workflow audit

Workflow topology is persisted in:

- `Workflow`
- `WorkflowStage`
- immutable published `WorkflowVersion`
- `WorkflowExecution`

Runtime execution consumes the persisted published version. Business stages execute through:

```
Workflow → Stage → Expert → Capability → Operation
```

The canonical workflow seeder creates persisted bootstrap data. It does not create a second runtime workflow engine.

No enterprise-specific runtime workflow branch or hardcoded executable stage graph was found in the Workflow execution path.

## MCP audit

Business mutating Tools are required to have exactly one Capability and exactly one Operation. Registry validation enforces uniqueness of:

- Capability identifier;
- Operation mapping;
- MCP Tool identifier;
- Tool class mapping.

Lifecycle MCP Tools remain explicitly classified and use the `mcp_agent_*` / `mcp_workflow_*` namespace.

Query/read/admin resources remain outside the business mutation invariant unless explicitly mapped as governed business Capabilities.

## Command Webhook audit

Command Webhooks follow:

```
authentication → Enterprise resolution → Capability allowlist → CapabilityInvocationService → Operation
```

They cannot select arbitrary Operation classes. Approval-sensitive Capabilities remain approval-gated.

## Provider webhook audit

Provider-result webhooks remain outside the business command surface:

```
Provider → Integration Webhook → IntegrationResultEnvelope → IntegrationResultService → reconciliation
```

They do not become business Capabilities and do not mutate arbitrary domain models directly.

## Filament audit

Filament remains the native application interface. It is intentionally not routed through MCP or the Capability registry merely to make the architecture aesthetically symmetrical, because software has suffered enough from symmetry for its own sake.

## Persistence and execution contracts

The governed execution boundary preserves, where applicable:

- Enterprise scope;
- actor identity;
- Agent assignment/execution;
- Expert provenance;
- approval context;
- correlation identity;
- idempotency;
- normalized failure handling;
- durable execution state.

Workflow executions remain bound to immutable published WorkflowVersions.

## Architectural enforcement

The material invariants are covered by automated tests including:

- Capability registry uniqueness and resolution;
- business Tool boundary enforcement;
- lifecycle Tool classification;
- non-MCP direct Operation guardrails;
- MCP surface inventory;
- Agent/Expert authorization;
- deterministic Workflow execution;
- Command Webhook authentication and replay protection;
- external-result reconciliation;
- cross-entry execution convergence;
- Knowledge and Memory resource execution.

## Final disposition

| Severity | Remaining unexplained findings |
|---|---:|
| P0 | 0 |
| P1 | 0 |
| P2 | 0 |
| P3 | 0 |

The repository now has one governed business execution substrate for the audited entry points, with Filament, provider-result reconciliation, and lifecycle MCP behavior remaining explicit exceptions.