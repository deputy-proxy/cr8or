# CR8OR Security Threat Model

## Security boundary

CR8OR treats the browser, AI model output, external integrations, webhooks and asynchronous workers as untrusted or independently fallible inputs. Server-side authorization, tenant scoping, approval validation and execution policy are authoritative.

## Threats and controls

| Threat | Control | Decision |
|---|---|---|
| Tenant/Enterprise data crossing | Enterprise/organization scope checks on Agent, Expert, Memory, Knowledge, Delegation, Integration and reporting services | Fail closed on scope mismatch |
| Capability escalation by model output | Capability registry + Agent permission + approval-sensitive authorization immediately before operation invocation | Model output never grants authority |
| Confused deputy through delegation | Source assignment, target assignment, organization/Enterprise equality, target capability permission and idempotency validation | Delegation cannot cross governed scope |
| Approval replay/self-approval | Approval fingerprint, active-stage checks, role checks, expiry and self-approval policy | Approval is consumed only after fresh authorization |
| Async resume bypass | Actor/assignment authorization plus current runtime-policy revalidation before resume | Waiting states are never auto-resumed |
| Webhook forgery | Provider-specific HMAC verification over the raw request body | Invalid/missing signatures rejected before ingestion |
| Webhook replay | Stable delivery ID is required for HTTP webhooks and is the primary deduplication identity | Same delivery cannot create a second result |
| External result injection | Result must correlate to exactly one provider/job identity and preserves tenant/job scope | Ambiguous correlation is rejected |
| Credential leakage | Integration connections store credential references only; metadata rejects secret-like keys | Secrets remain outside ordinary model/context surfaces |
| Historical tampering | Integration results are immutable; execution and approval provenance is append-oriented | Historical truth is retained |
| Queue duplication | Execution idempotency keys, unique job identity and terminal-state guards | Repeated delivery becomes a no-op |
| Runtime abuse | Environment/org/Enterprise/Agent/Expert policy inheritance with bounded steps, retries, timeout, context and provider selection | Unsafe policy fails closed |

## Security-sensitive historical records

Agent executions, decisions, approvals, delegations, integration jobs/results and security-relevant events are durable historical records. Operational health reporting is read-only and does not rewrite these records. Deletion or retention jobs must not silently erase provenance required to explain a governed action.

## External callback assumptions

A signed callback is authenticated as coming from the configured provider secret, not as authorization to perform arbitrary CR8OR work. The callback can only reconcile an already-created IntegrationJob, and ambiguous or missing job correlation is rejected.

Webhook payloads remain untrusted data. They cannot grant an Agent permission, alter an approval, create an assignment, change Enterprise scope, or bypass capability authorization.