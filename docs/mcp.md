# CR8OR MCP Architecture

MCP is the controlled AI-facing interface to CR8OR capabilities. It exposes authorized context and operations without becoming a duplicate application or domain layer.

## Resources vs Tools

**Resources** provide authorized contextual information to an AI client and should represent useful business context rather than raw database structure.

**Tools** request explicit capabilities that may change state or initiate execution. Current examples justified by the README include creating campaigns/tasks, requesting assets, creating render jobs, scheduling publications, registering transactions and requesting approvals.

## Authentication Boundary

The remote MCP entry point is registered at `/mcp` through Laravel MCP and protected by Laravel Passport's `auth:api` guard. Laravel MCP's OAuth discovery and dynamic client-registration routes are registered through `Mcp::oauthRoutes()`. Passport provides the OAuth identity layer; CR8OR authorization remains a separate application concern.

The current Laravel MCP 1.x integration speaks MCP protocol `2026-07-28` by default. Modern requests carry protocol metadata in `params._meta`; `server/discover` provides the server capability/identity discovery operation. Legacy `initialize` clients remain supported by the installed package.

Authentication identifies the caller; it does not itself grant organization, enterprise, Agent, Expert, resource or capability authority. Passport encryption keys are deployment state and are never committed to source control.

## Authorization Boundary

Authorization is enforced by CR8OR server-side for the relevant actor, organization and capability/resource. MCP visibility is never the security boundary.

## Invocation Boundary

**MCP request → authentication → authorization → validation → application/domain service → persistence/events/jobs → result**

MCP handlers must not implement business rules that belong in application/domain services.

## Validation and Errors

MCP requests validate required input before invoking application services. Errors should distinguish authentication, authorization, validation, unavailable-resource, business-rule and external-execution failures where practical. A failed state-changing operation must not be reported as successful merely because the transport succeeded.

## Auditability

State-changing MCP operations must be attributable to the calling actor and, where applicable, the agent, capability, target organization, approval context, external execution reference and resulting status.

## Direct Persistence Prohibition

MCP must not directly mutate Eloquent models, perform arbitrary database writes, or encode business invariants that belong in the application/domain service layer. Reads must also respect authorization and appropriate application boundaries.

## Naming and Scope

Future resources and tools should use stable, capability-oriented business names rather than internal table names. No additional naming catalogue is established until concrete MCP implementation begins.

The Phase 4.1 foundation implements the protected MCP transport and authentication boundary only. The server currently exposes no business resources or tools. Later Phase 4 issues add authorized resources, tools and AI execution capabilities.