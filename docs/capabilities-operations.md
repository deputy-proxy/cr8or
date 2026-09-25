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

The registry covers the governed Agent-facing Capability surface. Generic human CRUD and lifecycle MCP Tools remain outside this mapping until they become governed Agent Capabilities with an explicit Operation contract.