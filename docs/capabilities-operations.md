# Capability / Operation Runtime Boundary

CR8OR separates three concepts in governed AI execution:

| Concept | Canonical form | Responsibility |
|---|---|---|
| Capability | `marketing.content.create` | Reusable governed authority |
| Operation | `CreateContentItem` | Concrete executable business operation |
| Tool | `create-content-item` | MCP-facing interface |

Capabilities are authoritative in PHP through `App\Capabilities\CapabilityRegistry`. A capability must resolve to an explicit class implementing `App\Contracts\Operation`.

Authorization is evaluated before an Operation executes. Agent capability requests are resolved to their Operation after server-side authorization, and state-changing Agent content generation now executes through the registry rather than calling application services directly.

Operations delegate business rules to the existing application/domain services. They do not become a second persistence layer.

Experts remain advisory runtime components. They do not gain authority merely by declaring a capability or by being selected by an Agent. Permission, organization/Enterprise scope and approval requirements remain server-side concerns.

MCP Tools are transport/interface contracts. They must not contain duplicate business rules or bypass the Capability → Operation boundary.
