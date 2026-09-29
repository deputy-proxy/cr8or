# Phase 13 Failure Audit

## Verified runtime inventory

The Phase 13 completion audit was performed against the live repository on 2026-09-29.

| Boundary | Verified inventory |
|---|---:|
| Governed Capabilities | 49 |
| Operations | 71 PHP operation classes |
| MCP Tools | 109 registered server tools |
| MCP Resources | 4 registered resources |
| Current external execution boundaries | Canva, Postiz, Cloudflare R2 |
| Agent execution modes | interactive, autonomous |
| Queue execution | durable Agent execution jobs |
| Integration result boundary | webhook + polling reconciliation |

The MCP server is a transport boundary. Governed Tools resolve through the Capability Registry, and the four Resources use the shared MCP failure responder. Provider adapters do not own CR8OR business state.

## Canonical failure matrix

| Failure family | Canonical codes | Capability/Operation | MCP | Agent | External/integration | Queue |
|---|---|---:|---:|---:|---:|---:|
| Validation | validation.failed | yes | yes | yes | yes | yes |
| Authentication | authentication.required, external.authentication | yes | yes | yes | yes | yes |
| Authorization | authorization.denied, capability.denied, external.authorization | yes | yes | yes | yes | yes |
| Resource | resource.not_found, resource.unavailable | yes | yes | yes | yes | yes |
| Conflict | conflict.detected | yes | yes | yes | yes | yes |
| Lifecycle | lifecycle.invalid_transition, lifecycle.timeout, lifecycle.cancelled | yes | yes | yes | yes | yes |
| Business | business_rule.rejected | yes | yes | yes | yes | yes |
| Approval | approval.required, approval.denied, approval.expired | yes | yes | yes | where applicable | yes |
| Persistence | persistence.failed | yes | translated | yes | yes | yes |
| Queue | queue.failed, queue.timeout | n/a | n/a | yes | yes | yes |
| Provider | provider.configuration, provider.timeout, provider.rate_limited, provider.unavailable, provider.invalid_response, provider.rejected | yes | yes | yes | provider-specific | yes |
| External | external.authentication, external.authorization, external.timeout, external.rate_limited, external.unavailable, external.invalid_response, external.rejected, external.partial | yes | yes | yes | yes | yes |
| Configuration | configuration.invalid | yes | yes | yes | yes | yes |
| Serialization | serialization.failed | yes | yes | yes | yes | yes |
| Unexpected internal | internal.unexpected | yes | yes | yes | yes | yes |

Every standard Capability failure contract now declares the complete canonical code set. Registry validation prevents a governed Capability from existing without a failure contract.

## Failure propagation rules

1. A failure receives a correlation ID and a diagnostic ID.
2. Capability → Operation failures carry operation/capability provenance.
3. MCP Tools and Resources serialize the same canonical failure shape.
4. Agent Execution persists failure code, category, provenance and failure history.
5. Retry exhaustion preserves the original failure instead of replacing it with a generic retry-limit error.
6. Delegated child failures propagate code, category, provenance and historical failure evidence to the parent.
7. External provider failures are normalized before authoritative failure state is persisted.
8. If an external system may have accepted work before CR8OR can finalize local state, the external identifier is persisted while the integration job remains non-terminal.
9. Webhook and polling results reconcile through immutable IntegrationResult records.
10. Diagnostic internals are never included in client-visible MCP failures.

## Negative-path coverage

The suite includes representative negative-path coverage for:

- Capability registry completeness and uniqueness.
- Capability invocation and Operation failure provenance.
- MCP Tool and Resource canonical failure serialization.
- Agent interactive/autonomous execution failures, retry exhaustion and cancellation.
- Delegated child failure propagation.
- Canva timeout and idempotent retry behavior.
- Postiz timeout and idempotent retry behavior.
- R2 storage failure translation.
- Integration webhook authentication, deduplication, ambiguity and out-of-order results.
- Canonical exception translation and unexpected Throwable handling.
- Diagnostic redaction for authorization material, credentials, prompts and model context.

The suite does not multiply identical tests for every CRUD Tool. Materially equivalent Tools share the governed DomainMutation/DomainTransition execution path, and the registry verifies that each governed Capability has the same canonical failure contract.

## Security audit

Diagnostic logs may contain exception class, safe message, source location, trace metadata and governed provenance. The shared DiagnosticSanitizer redacts credentials, authorization headers, cookies, tokens, secrets, prompts, model context and chain-of-thought.

Correlation IDs are identifiers, not authorization credentials. Diagnostic IDs identify internal evidence and are not sufficient to retrieve or mutate business state.

## Operational diagnostic workflow

Given a client-visible correlation ID:

1. Find the structured execution-failure event carrying that correlation ID.
2. Read its diagnostic ID and failure code.
3. Locate the internal sanitized diagnostic record by diagnostic ID.
4. Use operation/capability/provider and execution identifiers to locate authoritative state.
5. For external execution, inspect the IntegrationJob/IntegrationResult history and external identifier.
6. For Agent execution, inspect failure history and child/parent execution linkage.

Duplicate log entries may share a diagnostic ID. The diagnostic ID represents the underlying exception identity, not each log line.

## Phase 13 audit conclusion

The verified runtime paths use one canonical failure contract, one governed Capability → Operation execution path, one MCP failure serialization boundary, and explicit external/queue failure handling.

No known failure path is intentionally collapsed into generic internal.unexpected; unexpected exceptions remain diagnosable through the canonical translator. Hidden chain-of-thought and sensitive runtime context are not part of the client-visible or diagnostic contract.