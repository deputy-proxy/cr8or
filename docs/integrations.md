# CR8OR Integrations

External integrations connect CR8OR to specialized execution systems. They do not transfer ownership of CR8OR business state.

## Integration Rule

**CR8OR owns business state and rules. External services execute specialized work and return results.**

Every integration should account for authentication, authorization, secret handling, validation, retries, timeouts, idempotency, external identifiers, failure states and auditability.

## Canonical Integration Boundary

CR8OR now has an explicit provider-neutral boundary consisting of:

- **Integration definition:** the logical business integration, such as creative, publishing, storage, media or source control.
- **Provider definition:** the concrete external system serving an integration, such as Canva, Postiz, Cloudflare R2, CR8OR Media or GitHub.
- **IntegrationConnection:** organization/enterprise-scoped connection state and an opaque credential reference.
- **CredentialReference:** a typed identifier for externally managed credential material. Credential material itself never belongs in the domain record.
- **External execution context:** enterprise, integration, provider, resource context, correlation identifier and idempotency key.
- **Webhook contract:** a provider event envelope that can later be authenticated, validated and reconciled without giving the provider direct mutation authority.
- **Integration event contract:** a versioned external-integration fact envelope. Durable event taxonomy and delivery semantics are handled separately by the platform event phase.
- **IntegrationJob:** the recorded execution attempt and normalized failure state.
- **ExternalResource:** the stable external identifier and relationship back to CR8OR-owned records.

The provider-neutral contracts live in App\\Data\\Integrations, while provider adapters remain under App\\Providers and implement application contracts. Domain operations remain under App\\Services and App\\Operations; providers do not become an alternative application/domain layer.

## Connection Lifecycle

IntegrationConnection supports:

- `active`: usable for new external execution;
- `disabled`: deliberately unavailable;
- `degraded`: known operational problem; new execution is blocked;
- `revoked`: credential or authorization relationship has been permanently invalidated.

Only active connections are operational. Revoked connections cannot return to an operational state.

Connection metadata is restricted to non-secret configuration. Keys and nested keys that indicate tokens, secrets, passwords, API keys or private keys are rejected server-side.

## Provider Mapping

The current provider registry maps the implemented external boundaries as follows:

| Integration | Provider | Responsibility |
|---|---|---|
| creative | Canva | Design execution |
| publishing | Postiz | Social publication execution |
| storage | Cloudflare R2 | Canonical file storage |
| media | CR8OR Media | Media generation/rendering |
| source_control | GitHub | Repository/software execution |

This registry is descriptive and authoritative for the application boundary. It does not make external providers authoritative business-state stores.

## Canva

**Role:** Human-assisted creative workflow and design execution. CR8OR remains authoritative for the originating content/media request and its authorization/history.

The current boundary supports creating a Canva design through the Canva REST API and recording the resulting external design as an ExternalResource. Canva's REST API uses OAuth 2.0 Authorization Code with PKCE and user-authorized scopes. The current adapter expects an externally managed access credential resolved from a credential_reference; CR8OR does not persist access tokens or client secrets in integration records.

The implemented design-create contract uses Canva's POST /rest/v1/designs endpoint with the design:content:write scope. Canva returns a design identifier and temporary edit/view URLs, which CR8OR records as external-reference metadata rather than treating them as authoritative business state.

The adapter normalizes connection failures, rate limits, provider failures and invalid provider responses. Operations carry CR8OR idempotency keys and correlation identifiers. CI uses FakeCanvaClient and never requires live Canva credentials.

The current implementation deliberately does not make preview-only Canva APIs part of the contract. Preview functionality remains outside this boundary until separately verified and approved.

## n8n
**Role:** Optional future MCP-connected automation capability. An Automatiser Expert may use n8n for multi-step external automation when a business workflow requires it. n8n must not become the authoritative store for CR8OR business state or the primary CR8OR orchestration layer.

## Cloudflare R2
**Role:** Canonical file and generated-media storage. R2 stores files referenced by CR8OR; CR8OR owns business metadata, lifecycle and authorization.

## CR8OR Media
**Role:** Specialized media generation/rendering execution. CR8OR owns the originating request, lifecycle state and relationship to business/content state.

## Postiz
**Role:** Social publishing execution. CR8OR owns publication intent, authorization and recorded publication state. The Postiz adapter is explicitly identified as the publishing integration's provider and receives idempotency through the existing PublishingRequest contract.

## GitHub
**Role:** Software-development execution and repository workflow. GitHub is not the authoritative source for CR8OR business domain state.

## AI Model Providers
**Role:** Reasoning and generation. Model providers generate outputs but do not own CR8OR business knowledge, authorization or historical state.

## Failure, Retry and Webhook Rules

External calls may fail after CR8OR accepts an operation. Such states must be explicit. Retryable operations should use idempotency where duplicate execution could create duplicate effects. External identifiers should be persisted when needed for callbacks or reconciliation. Incoming webhooks must be authenticated where supported, validated, safely deduplicated and associated with relevant integration state.

Webhook ingestion, external-result reconciliation, durable integration-event delivery and concrete long-running polling workers are separate capabilities and must use the canonical boundary rather than bypass it.