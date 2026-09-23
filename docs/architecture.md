# CR8OR Architecture

## Purpose

This document defines the implementation-facing architecture of CR8OR Core. It establishes ownership boundaries between the Laravel application, AI agents, MCP, orchestration, external execution services, and persistence.

## Architectural Model

**Agents reason and request governed capabilities → CR8OR owns state, rules and authorization → MCP exposes capabilities → n8n orchestrates → external services execute.**

| Boundary | Owns | Must not own |
|---|---|---|
| AI agents | Reasoning, planning, recommendations and decisions within assigned authority | Direct database access, hidden authorization, authoritative business state |
| Laravel / CR8OR | Business state, domain rules, application services, authorization, history | AI reasoning or specialized external execution |
| Filament | Administrative and operational interaction with CR8OR | Security decisions that are not enforced server-side |
| Application/domain services | Explicit business operations and invariants | AI transport concerns or duplicated MCP logic |
| Policies / authorization | Authority and data-access decisions | Business execution itself |
| Events | Meaningful state-change notifications | Ownership of business state |
| Jobs | Asynchronous CR8OR-owned work | Becoming an alternative business-state store |
| MCP | Controlled AI-facing capability interface | Direct model/database mutation or duplicated business logic |
| n8n | Cross-service workflow orchestration | Authoritative CR8OR business state |
| External workers/services | Specialized execution | Authoritative CR8OR business state |
| R2 / storage | Canonical files and generated media | Business authorization or domain rules |

## Application Boundaries

CR8OR is a Laravel application. Laravel owns the authoritative operational record and the rules governing mutations.

Application services are the boundary for important operations. Domain logic must not be hidden inside controllers, MCP handlers, workflow nodes, or external services.

Filament is the administrative and operational interface. UI controls may reflect authorization, but server-side authorization remains mandatory.

Laravel jobs and events are used for asynchronous work that belongs to CR8OR. Work that coordinates multiple external services belongs at the orchestration boundary.

Workflow, Job and Execution records provide the authoritative trace for CR8OR-owned background work. A Workflow retains the originating Enterprise and optional operational work context, a Job represents one idempotent logical background operation, and an Execution records an attempt through an explicit pending/running/succeeded/failed lifecycle. This is execution tracking, not a general workflow engine. Concrete provider execution remains outside this boundary.

## AI and MCP Boundaries

AI agents reason over authorized CR8OR context. An agent may propose a plan or request a capability, but technical access to a tool does not itself grant business authority. Model calls cross an internal provider-neutral `App\AI\Contracts\ModelProvider` boundary. Laravel AI is currently the concrete infrastructure adapter, while fake providers keep runtime tests independent of network access and provider credentials.

MCP translates AI-facing requests into controlled CR8OR capabilities. MCP must authenticate the caller, establish authorization context, validate inputs, and invoke application/domain services. MCP is not a second domain layer.

## Orchestration and Execution Boundaries

n8n coordinates workflows that cross service boundaries or require external orchestration. It may receive execution instructions and return results, but CR8OR remains the source of truth for resulting business state.

Specialized services such as media renderers, publishing systems, Canva, GitHub, and AI providers perform bounded execution. Their responses are external execution results that CR8OR may persist or reconcile where they affect business state.

## Request Lifecycle

1. An authenticated user or AI client establishes identity.
2. An agent reasons over authorized context and forms an intent.
3. MCP or an application interface translates the intent into a named capability request.
4. Server-side authorization checks actor, organization, capability and target state.
5. An approval gate is applied when policy requires human approval.
6. An application/domain service validates and executes the business operation.
7. CR8OR persists the authoritative state transition.
8. Events and jobs communicate the resulting state change.
9. n8n and/or an external service performs specialized execution when required.
10. The external result is correlated with the originating CR8OR operation.
11. CR8OR records relevant result, status and audit information.
12. Reporting and subsequent agent context derive from authoritative CR8OR state.

## Prohibited Shortcuts

- Agents must not connect directly to the database.
- MCP must not mutate Eloquent models as a substitute for application services.
- Controllers and UI components must not bypass authorization.
- n8n must not become a shadow database for CR8OR business state.
- External services must not silently redefine CR8OR business state.
- Domain invariants must not exist only in prompts or workflow nodes.
- External retries must not create duplicate business effects where idempotency is required.

This document establishes boundaries, not a final class hierarchy or database schema.
