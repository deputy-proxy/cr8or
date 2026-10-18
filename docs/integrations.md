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

Canva, Cloudflare R2, CR8OR Media and GitHub remain specialized external execution/storage boundaries. Social publishing uses CR8OR's destination-aware adapter registry. The LinkedIn text-only, Facebook Pages text/link/single-image, Instagram single-image, TikTok direct-post video, YouTube video, and Threads text/image/video adapters are implemented but remain disabled; each network adapter must be explicitly enabled only after its API permissions, supported capabilities and deployment configuration are verified. The adapter contract and capability-verification rules are documented in [`social-publishing-adapters.md`](social-publishing-adapters.md). n8n is an optional MCP-connected automation capability, not CR8OR's primary orchestration layer or authoritative business-state store.

## Integration invariant

Integration webhooks never mutate arbitrary domain models directly. They authenticate, validate, normalize and reconcile through the integration boundary.
## CR8OR Media project references

CR8OR Media calls its tenant a **Project**. The corresponding authoritative CR8OR domain object is an **Enterprise**; this integration contract does not use CR8OR's separate `Project` model.

The read-only contract is protected by OAuth 2.0 client credentials and the explicit `enterprise:read` scope:

- `GET /api/integrations/project-references` lists only Enterprise records assigned to the calling OAuth client.
- `POST /api/integrations/project-references/validate` accepts `{"ids":[1,2]}` and returns the matching references only if every ID is assigned to that client.
- Each reference contains the stable numeric Enterprise `id`, `name` as a display label, and `status`. Names are never identifiers or authorization inputs.
- Unknown and out-of-scope IDs return the same 404 response so the API cannot be used to probe another tenant's records. The validation request accepts 1–100 distinct positive integer IDs.
- Only OAuth client-credentials grants are accepted. User-bound tokens, missing/invalid tokens, and tokens without `enterprise:read` are rejected.

### Provisioning a client

Create a dedicated Passport OAuth client with the `client_credentials` grant, then persist one `integration_enterprise_access` row for each Enterprise that client may read (`oauth_client_id`, `enterprise_id`, `active=true`). Access is explicitly assigned per client and Enterprise; an authenticated client has no default access to any Enterprise. Set `active=false` to revoke a single mapping without revoking the OAuth client. Revoke the OAuth client/token separately when the integration itself must be disabled.

Example request:

```http
GET /api/integrations/project-references
Authorization: Bearer <client-credentials-token-with-enterprise:read>
Accept: application/json
```

```json
{
  "data": [
    {"id": 42, "name": "Example Enterprise", "status": "active"}
  ]
}
```

The `id` is the stable CR8OR Enterprise ID that CR8OR Media stores as its project reference. Do not resolve references by Enterprise name or slug.
