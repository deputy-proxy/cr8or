# Capability / Operation Runtime Boundary

CR8OR separates three concepts in governed AI execution:

| Concept | Canonical form | Responsibility |
|---|---|---|
| Capability | `marketing.content.create` | Reusable governed authority |
| Operation | `CreateContentItem` | Concrete executable business operation |
| Tool | `create-content-item` | MCP-facing interface |

## Runtime invariant

Governed Capability mappings are authoritative in PHP through `App\Capabilities\CapabilityRegistry`. The registry is the single runtime mapping for:

- stable Capability key;
- exactly one Operation class;
- exactly one MCP Tool identifier and Tool class;
- the Tool input contract;
- the Tool output contract;
- the authorization boundary;
- the approval requirement.

The registry validates the mapping before it can be resolved. Duplicate Capability identifiers, Operation classes, Tool identifiers, or Tool classes are rejected. A governed Tool that is not registered cannot resolve to an Operation.

The registry is runtime-only. CR8OR does not persist a duplicate Capability registry merely to validate executable mappings.

## Execution

A governed MCP Tool resolves its Capability and Operation through the registry before the Operation executes:

**MCP Tool → Capability → Operation → Application / Domain Service**

Tool registration does not grant authority. Capability declaration does not grant authorization. Authorization and approval remain server-side concerns and are evaluated by the existing authorization/application services before state-changing execution.

Operations delegate business rules to the existing application/domain services. They do not become a second persistence layer.

## Discovery

Capability discovery remains derived from enabled runtime Agent and Expert declarations. Discovery reconciles every declared Capability against `CapabilityRegistry`, and exposes the registry's Operation and Tool mapping. This means discovery cannot silently advertise an executable Capability that has no governed runtime mapping.

## Scope

The registry covers every state-changing MCP Tool. Human authorization still uses the target domain Policy, while Agent-backed authorization additionally requires the Tool's explicit Capability permission. The generic mutation/transition fallbacks are not registered and cannot be used as Agent authority; governed mutation and transition Tools must resolve directly through their explicit CapabilityRegistry definitions.
## Application invocation boundary

Application code invokes a governed Capability through `App\\Services\\CapabilityInvocationService` using `App\\Data\\CapabilityInvocationRequest`.

The canonical application path is:

**Application / Agent adapter → CapabilityInvocationService → CapabilityRegistry → Operation → Application / Domain Service → Persistence**

The invocation service is transport-independent. It resolves the Capability only through `CapabilityRegistry`, re-evaluates server-side authorization, creates or validates approval state when required, preserves correlation/idempotency/provenance context, and normalizes the Operation result.

`CapabilityExecutionService` is the Agent-runtime adapter around this same application boundary. It does not own a second execution engine.

MCP governed Tools are interface adapters and delegate execution through the application invocation boundary. MCP remains a transport/interface surface and is not required for application-level Capability execution.

An application invocation may be human-scoped to an Enterprise or Agent-backed with an Assignment and Execution. Agent-backed requests retain Enterprise isolation and Agent Capability permissions. Approval-sensitive Agent requests return a resumable `waiting` result until a valid approval is supplied.
### Enterprise Context

| Capability | Operation | MCP adapter | Purpose |
|---|---|---|---|
| `enterprise.context.create` | `EnterpriseContextCreate` | `create-enterprise-context` | Establish the authoritative Enterprise Context record. |
| `enterprise.context.retrieve` | `EnterpriseContextRetrieve` | `retrieve-enterprise-context` | Retrieve the authorized, canonical Enterprise Context representation consumed by downstream Agent/Expert runtime composition. |

`EnterpriseContextRetrieve` is the application retrieval contract. It delegates to `EnterpriseContextService`, which enforces Enterprise authorization, requires the persisted context record to exist, and serializes the current Enterprise Context through `EnterpriseContextAssembler`. MCP resources may adapt this operation for their transport-specific response shape; they do not own a parallel retrieval implementation.
### Knowledge lifecycle

| Capability | Operation | MCP adapter | Purpose |
|---|---|---|---|
| `knowledge.item.create` | `CreateKnowledgeItem` | `create-knowledge-item` | Persist authoritative Enterprise-scoped Knowledge Item content and its version. |
| `knowledge.index.create` | `CreateKnowledgeIndex` | `create-knowledge-index` | Build the derived Knowledge index from authoritative Knowledge. |
| `knowledge.unit.create` | `CreateKnowledgeUnit` | `create-knowledge-unit` | Rebuild or retrieve one derived Knowledge Unit from authoritative Knowledge. |
| `knowledge.retrieve` | `RetrieveKnowledge` | `retrieve-knowledge` | Retrieve authorized indexed Knowledge with normalized provenance. |

The Knowledge lifecycle remains split between authoritative persistence and derived representations. `knowledge.item.create` persists the business Knowledge Item and version; index and unit capabilities invoke the existing indexing/resource services; `knowledge.retrieve` invokes the existing retrieval service. Repeated indexing rebuilds the derived representation rather than creating duplicate authoritative Knowledge. The capability-native application path does not require MCP.

### Agent Assignment lifecycle

| Capability | Operation | MCP adapter | Purpose |
|---|---|---|---|
| `agent.assignment.create` | `CreateAgentAssignment` | `create-agent-assignment` | Persist an Enterprise-scoped Agent Assignment with objective, requirements, context and idempotency state. |
| `agent.assignment.update` | `UpdateAgentAssignment` | `update-agent-assignment` | Update Assignment definition without bypassing lifecycle rules. |
| `agent.assignment.transition` | `TransitionAgentAssignment` | `transition-agent-assignment` | Apply explicit Assignment state-machine transitions. |

These Capabilities operate on the durable Assignment resource. They do not turn the target Assignment into an Agent-backed execution request. Agent execution remains a separate Capability (`agent.execute`) that uses an Assignment as its runtime authority.

### Capability-native E2E

`E2E-CAPABILITY-20260928` is the definitive application-path validation. It starts at `CapabilityInvocationService` and exercises the persisted Enterprise → Context → Knowledge → Strategy/Work → Agent Assignment → Agent Execution → Expert → Memory → Delegation → Campaign → Content → Review → Approval graph without invoking MCP CRUD/action tools or Railway Sandbox. The test records the resolved Operation for every Capability and emits a machine-readable persisted graph at `storage/app/e2e/E2E-CAPABILITY-20260928.json`.

`enterprise.create` is the bootstrap exception to the normal Enterprise-scoped invocation contract. It may be invoked without a pre-existing Enterprise, provided the actor is authorized for the target Organization. All other human-scoped Capabilities require an Enterprise; Agent-backed Capabilities additionally require the matching Assignment and Execution.

## Agent-backed interactive execution

agent.execute supports two intentionally distinct runtime modes.

### Interactive

Interactive mode is a deterministic, caller-supplied Capability workflow:

AgentExecutionService -> InteractiveCapabilityStepRunner -> CapabilityExecutionService -> CapabilityInvocationService -> CapabilityRegistry -> Operation

The caller supplies the Capability plan. Requests may be grouped into ordered durable steps with the optional step field. Each step is persisted as an AgentExecutionStep before Capability execution. Interactive execution never calls ModelProvider and never dispatches the autonomous execution job.

An empty plan means waiting_for_input. A Capability returning waiting means the current step is waiting and the parent execution is waiting_for_approval. Resume continues from persisted state and can merge updated request data, such as an approved approval_request_id.

### Autonomous

Autonomous mode remains model-driven. The ModelProvider produces structured Capability requests, which are then adapted through the same CapabilityExecutionService and canonical Capability invocation boundary.

Neither mode creates a second authorization or Operation execution path. Interactive completion requires completion of the supplied Capability workflow, not merely creation of an execution context.