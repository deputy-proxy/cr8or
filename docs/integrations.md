# CR8OR Integrations

External integrations execute specialized work without owning CR8OR business state.

## Command Webhooks

Command Webhooks are controlled business entry points:

```
Authenticated command → Capability → Operation → Application/Domain → State
```

They use HMAC authentication, a configured Capability allowlist, Enterprise identity resolution, required correlation/idempotency metadata and the canonical Capability invocation boundary. Delivery state is persisted for replay protection. Approval-required Capabilities cannot be bypassed by a command credential.

## External-result Webhooks

Provider-originated result delivery is a separate integration reconciliation boundary:

```
External Provider → Integration Webhook → IntegrationResultEnvelope → IntegrationResultService → IntegrationJob
```

These webhooks reconcile external execution results. They are not business command entry points and do not become business Capabilities.

## Provider boundary

Provider adapters remain behind provider-neutral integration contracts. CR8OR owns authorization, business state, correlation, idempotency and reconciliation. External identifiers and provider results are persisted as integration state.

## Failure and retry

External failures use the canonical CR8OR failure taxonomy. Integration-result delivery is deduplicated, and delayed or out-of-order terminal results do not overwrite authoritative terminal state. Partial external execution remains observable for reconciliation.

## Current providers

Canva, Postiz, Cloudflare R2, CR8OR Media and GitHub remain specialized external execution/storage boundaries. n8n is an optional MCP-connected automation capability, not CR8OR's primary orchestration layer or authoritative business-state store.

## Integration invariant

Integration webhooks never mutate arbitrary domain models directly. They authenticate, validate, normalize and reconcile through the integration boundary.