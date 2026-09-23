# CR8OR Security & Authorization

This document defines the security boundaries that must remain true as CR8OR moves from the completed Phase 1 foundation into Agent, Expert and MCP implementation.

## Security Principles

1. **Server-side authority:** UI visibility, prompts, model behavior and tool availability are never security boundaries.
2. **Organization isolation:** Business data and operational authority must remain isolated by organization.
3. **Least privilege:** Actors, Agents and integrations receive only the authority required for their assigned scope.
4. **Explicit execution:** State-changing operations use named application/domain capabilities rather than arbitrary model or database access.
5. **Human approval where required:** Sensitive operations may require explicit human approval according to policy.
6. **Traceability:** Important actions must be attributable and auditable.
7. **Historical integrity:** Security and business records must remain interpretable after policies, configuration or runtime behavior change.
8. **External-boundary discipline:** External systems execute bounded work but do not become authoritative for CR8OR business state.
9. **Secrets are not business data:** Credentials and secrets must not be persisted in plaintext domain records or committed to source control.
10. **Failure must be explicit:** Authentication, authorization, validation, execution and external failures must not be silently treated as success.

## Authentication

Protected application operations require an authenticated user or an explicitly approved machine/AI client identity.

Authentication establishes **who is making a request**. It does not grant permission to perform the requested operation.

AI/MCP clients must have an identifiable client or actor identity before accessing protected resources or capabilities. The exact authentication mechanism is an implementation decision for the relevant API/MCP phase.

## Organization Isolation

Organizations are the primary isolation boundary for business data and operational authority.

Rules:
- Every organization-owned record must remain attributable to its organization directly or through an enterprise-owned relationship.
- A user must not read or mutate another organization's business state.
- An Agent operating for one organization must not obtain another organization's context unless an explicit, authorized cross-organization capability is introduced.
- Queries, application services, policies and MCP resources must enforce the same organization boundary.
- UI filtering is insufficient as an isolation mechanism.

Enterprise ownership is the principal path for Phase 1 enterprise-domain authorization.

## Authorization

Authorization is evaluated server-side for every protected operation.

Authorization should consider, as applicable:
- authenticated actor;
- organization;
- enterprise and target resource;
- role and permission;
- Agent assignment;
- Agent capability;
- requested operation;
- current resource state;
- approval requirements;
- applicable policy.

A successful authentication check must never be treated as successful authorization.

## Agent & Expert Security

Agents and Experts are runtime components, not independent security principals merely because they are PHP classes.

Persistent descriptors register runtime components but do not grant authority.

Agent execution authority must come from explicit server-side assignment, capability and permission rules. Expert delegation does not increase authority.

The following are prohibited:
- authority encoded only in prompts;
- direct database access from runtime components;
- bypassing application/domain services to mutate models;
- using one Agent or Expert to evade another Agent's permission boundary;
- treating model-generated output as an authorized mutation.

## Capability & Tool Security

A capability is an application-level operation with explicit authorization requirements.

A tool is an invocation mechanism. Tool visibility, discoverability or model access does not constitute permission.

Every state-changing capability must:
1. establish actor and organization context;
2. validate input;
3. authorize the requested operation and target;
4. evaluate approval requirements;
5. invoke the appropriate application/domain service;
6. persist the authoritative state transition;
7. record relevant audit information.

MCP tools must follow the same rules. MCP must not become a bypass around application authorization.

## Approval Controls

High-impact operations may require explicit human approval. Examples include:
- financial mutations;
- external spending;
- publication;
- destructive changes;
- credential use;
- other operations designated sensitive by policy.

Approval must be evaluated server-side and tied to the relevant operation, actor, target and policy context. CR8OR now represents an approval requirement on an Agent permission and persists an Approval Request with a pending, approved or rejected lifecycle, immutable request context, an approver attribution and an expiration boundary. Only organization owners and administrators may approve or reject requests.

Delegating an operation to another Agent, Expert, tool or workflow must not remove an approval requirement. The capability authorizer requires an approved, unexpired request matching the actor, assignment, capability, execution and target context before allowing a capability marked as requiring approval.

## Data Protection

CR8OR should minimize sensitive data exposure and provide only the context required for an authorized operation.

Runtime prompts and model context should use authorized business data rather than bypassing application access controls.

Secrets must:
- remain outside source control;
- be supplied through the appropriate environment/secret-management mechanism;
- not be copied into ordinary business records;
- not be exposed through logs, audit entries or model context unless explicitly required and authorized.

Credential references may be persisted, but plaintext credentials should not be stored as ordinary domain attributes.

## MCP Security Boundary

MCP is an external interface to CR8OR capabilities. The current remote endpoint is `/mcp`, protected by Laravel Passport through the `auth:api` guard. Laravel MCP OAuth discovery/registration endpoints remain separate from the protected MCP endpoint.

The MCP boundary must:
- authenticate the client;
- establish actor and organization context;
- authorize resource and tool access;
- validate inputs;
- invoke application/domain services;
- return explicit failures;
- preserve audit context.

MCP resources must not expose data merely because a client can construct a resource identifier.

MCP tools must not directly mutate Eloquent models or encode duplicated business rules.

## External Integrations

n8n, media workers, publishing services, GitHub, Canva, storage providers and AI model providers are execution boundaries.

External services must not silently become authoritative sources of CR8OR business state.

Integration requests should use:
- authenticated connections;
- validated payloads;
- external identifiers;
- correlation identifiers;
- appropriate timeouts;
- retries where safe;
- idempotency where repeated execution could duplicate business effects.

Webhook handlers must validate authenticity where supported, authorize the relevant integration, validate payloads and safely handle retries.

## Auditability

Important actions must record enough context to reconstruct:
- who or what initiated the action;
- which Agent acted, where applicable;
- which capability was requested;
- which organization and target were involved;
- which authorization context applied;
- whether approval was required and obtained;
- what external execution occurred;
- what result and final status were produced.

Audit records are not a substitute for authorization. They provide evidence after authorization and execution decisions have occurred.

## Historical Integrity

Security-relevant and business-significant historical records must remain interpretable after policies or runtime configuration change.

Candidates for immutable or append-only treatment include:
- approvals;
- audit entries;
- financial transactions;
- important Agent decisions;
- external execution records;
- publication results.

Phase 1 Enterprise Decisions currently preserve actor identity and decision time while allowing substantive content to remain mutable. A later implementation must explicitly choose the required immutable/versioned model before Agents depend on these records as durable historical evidence.

## Failure, Retry and Recovery

Security boundaries must survive failures and retries.

Requirements:
- failed authorization must not produce a successful mutation;
- failed approval must not be treated as approval;
- partial external execution must be observable;
- retries must be idempotent where possible;
- external failures must not silently corrupt authoritative CR8OR state;
- audit records should distinguish requested, approved, executing, succeeded and failed states where the operation requires those distinctions.

## Logging & Observability

Operational logs should support diagnosis without leaking secrets or unnecessary sensitive business data.

Logs and audit records should distinguish:
- authentication failures;
- authorization failures;
- validation failures;
- application/domain failures;
- external execution failures.

Sensitive credentials, tokens and unnecessary model context must not be written to ordinary logs.

## Phase 2 Verified Security Boundary

The current Phase 2 implementation establishes and tests:
- explicit Agent/Expert runtime contracts;
- descriptor registration without authority leakage;
- organization/enterprise-scoped assignments;
- capability and permission enforcement;
- execution and decision historical records;
- approval enforcement tied to actor, assignment, capability, execution and target context;
- Filament administration backed by server-side policies and scoped queries;
- automated authorization, isolation and negative-path tests.

MCP authentication/authorization, provider-specific execution security and broader Agent orchestration remain deferred to later implementation phases. The broader permission catalogue and policy language also remain intentionally limited to the explicit Phase 2 capability boundary.
