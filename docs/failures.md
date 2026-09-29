# CR8OR Failure Contract

## Authority

CR8OR has one provider-neutral, transport-neutral failure contract: `App\\AI\\Contracts\\ExecutionError`.

Transport adapters may serialize this contract, but they must not redefine its taxonomy or business semantics.

## Contract

Every client-visible failure has this shape:

```json
{
  "success": false,
  "error": {
    "type": "validation",
    "code": "validation.failed",
    "message": "The request failed validation.",
    "retryable": false,
    "correlation_id": "corr-123",
    "diagnostic_id": "diag-456",
    "operation": "CreateAgentAssignment",
    "capability": "agent.assignment.create",
    "tool": "create-agent-assignment",
    "details": {
      "fields": {
        "name": ["The name field is required."]
      }
    }
  }
}
```

### Required semantics

- `type` is a stable taxonomy category.
- `code` is a stable machine-readable identifier.
- `message` is safe for external clients.
- `retryable` is explicit and must not be inferred by clients.
- `correlation_id` identifies the request/execution chain.
- `diagnostic_id` identifies the failure event for internal diagnosis.
- `operation`, `capability`, and `tool` identify known execution provenance and are omitted when unavailable.
- `details` contains only safe, structured client-visible information.

Internal exception messages, stack traces, credentials, tokens and hidden model context are not part of the client contract.

## Taxonomy

| Type | Purpose |
| --- | --- |
| `authentication` | Missing or invalid actor authentication |
| `authorization` | Actor is authenticated but not authorized |
| `validation` | Input or request validation failed |
| `resource` | Required resource is unavailable or missing |
| `conflict` | Requested state conflicts with authoritative state |
| `lifecycle` | Requested lifecycle transition is invalid |
| `business_rule` | A domain/application rule rejected the operation |
| `capability` | Governed Capability resolution or authorization failed |
| `approval` | Approval is required, denied or expired |
| `provider` | Model/provider boundary failed |
| `external` | Non-model external execution failed |
| `persistence` | Authoritative persistence failed |
| `queue` | Queue dispatch/worker execution failed |
| `configuration` | Required runtime configuration is invalid |
| `serialization` | Data could not be serialized/deserialized safely |
| `internal` | Unexpected application failure |

## Code rules

Codes are centrally defined in `FailureCode`.

Codes:

1. describe the business/operational failure;
2. remain stable across PHP exception refactors;
3. use lowercase dot-separated identifiers;
4. do not contain class names, stack information, provider secrets or implementation paths;
5. remain provider-neutral unless the provider boundary itself is the failure category.

The fallback for an unknown exception is `internal.unexpected`.

## Retryability

`retryable` is an explicit contract field. A client must not infer retryability from the error type, HTTP status, message or code prefix.

Retryability describes whether repeating the same logical operation may be valid. Later exception-translation and integration phases own the detailed mapping of infrastructure and provider failures.

## Validation details

Validation failures may include field-level details:

```json
{
  "fields": {
    "name": ["The name field is required."]
  }
}
```

Only safe validation messages and field identifiers may cross the client boundary.

## Diagnostic separation

The client receives a correlation ID and diagnostic ID, but not the underlying exception identity or stack trace.

Internal diagnostic logging may use the diagnostic ID to locate the authoritative failure event. Client-visible data must remain safe even when the underlying exception contains sensitive information.

## Synchronization

The same failure contract applies to synchronous and asynchronous execution. Transport-specific wrappers may add protocol metadata, but they must not invent alternate failure codes or categories.## Exception-to-Failure Translation Boundary

All known exception families are translated by `App\Services\FailureTranslator` before they cross an application boundary. `ExecutionError::from()` remains only as a compatibility entry point and delegates to this translator.

The translator is shared by:

- JSON/API exception rendering in `bootstrap/app.php`;
- MCP tool failure handling;
- Agent and Expert execution failure paths;
- queue-facing execution code through the same application service;
- provider and external integration boundaries.

Known exceptions map deterministically to the canonical taxonomy. Unknown `Throwable` values become `internal.unexpected`.

Each exception object receives one diagnostic ID for the lifetime of the translator service. The internal diagnostic record retains exception class, message, source location and trace metadata. These diagnostics never appear in the client failure payload.

Boundary rules:

1. Do not swallow exceptions silently.
2. Do not return success after an operation failed.
3. Do not expose exception messages or stack traces to clients unless explicitly classified as safe details.
4. Do not translate the same exception object into multiple diagnostic records.
5. Preserve the original exception as the internal diagnostic source while returning the canonical failure contract externally.