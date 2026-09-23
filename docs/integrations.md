# CR8OR Integrations

External integrations connect CR8OR to specialized execution systems. They do not transfer ownership of CR8OR business state.

## Integration Rule

**CR8OR owns business state and rules. External services execute specialized work and return results.**

Every integration should account for authentication, authorization, secret handling, validation, retries, timeouts, idempotency, external identifiers, failure states and auditability.

## Integration Boundary

CR8OR uses the generic IntegrationConnection, IntegrationJob and ExternalResource records for external-system state.

- IntegrationConnection identifies an organization/enterprise-scoped provider connection and stores only a credential reference, never plaintext credentials.
- IntegrationJob records an external operation, correlation context, attempts, idempotency key and normalized failure state.
- ExternalResource stores stable external identifiers and references them back to CR8OR Content, Assets and, where applicable, AgentExecution.
- Provider adapters implement application contracts and are replaceable by deterministic fakes in CI.
- External failures remain failed/explicit integration state and cannot silently create successful CR8OR business state.

The generic records are provider-neutral. Provider-specific behavior belongs in the adapter/application boundary.

## Canva

**Role:** Human-assisted creative workflow and design execution. CR8OR remains authoritative for the originating content/media request and its authorization/history.

The current boundary supports creating a Canva design through the Canva REST API and recording the resulting external design as an ExternalResource. Canva's REST API uses OAuth 2.0 Authorization Code with PKCE and user-authorized scopes. The current adapter expects an externally managed access credential resolved from a credential_reference; CR8OR does not persist access tokens or client secrets in integration records. citeturn0search3turn0search8

The implemented design-create contract uses Canva's POST /rest/v1/designs endpoint with the design:content:write scope. Canva returns a design identifier and temporary edit/view URLs, which CR8OR records as external-reference metadata rather than treating them as authoritative business state. citeturn1search0

The adapter normalizes connection failures, rate limits, provider failures and invalid provider responses. Operations carry CR8OR idempotency keys and correlation identifiers. CI uses FakeCanvaClient and never requires live Canva credentials.

The current implementation deliberately does not make preview-only Canva APIs part of the contract. Canva's current documentation identifies design-copy/brand-template creation and some asset URL-upload APIs as preview functionality, so those remain outside this boundary until separately verified and approved. citeturn1search0turn0search2

## n8n
**Role:** Cross-service workflow orchestration. n8n may coordinate multi-step workflows but must not become the authoritative store for CR8OR business state.

## Cloudflare R2
**Role:** Canonical file and generated-media storage. R2 stores files referenced by CR8OR; CR8OR owns business metadata, lifecycle and authorization.

## CR8OR Media
**Role:** Specialized media generation/rendering execution. CR8OR owns the originating request, lifecycle state and relationship to business/content state.

## Postiz
**Role:** Social publishing execution. CR8OR owns publication intent, authorization and recorded publication state.

## GitHub
**Role:** Software-development execution and repository workflow. GitHub is not the authoritative source for CR8OR business domain state.

## AI Model Providers
**Role:** Reasoning and generation. Model providers generate outputs but do not own CR8OR business knowledge, authorization or historical state.

## Failure, Retry and Webhook Rules

External calls may fail after CR8OR accepts an operation. Such states must be explicit. Retryable operations should use idempotency where duplicate execution could create duplicate effects. External identifiers should be persisted when needed for callbacks or reconciliation. Incoming webhooks must be authenticated where supported, validated, safely deduplicated and associated with relevant integration state.

Provider-specific OAuth persistence/vault integration, webhook endpoints and concrete long-running polling workers remain separate capabilities from this minimal Canva boundary.