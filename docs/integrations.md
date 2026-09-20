# CR8OR Integrations

External integrations connect CR8OR to specialized execution systems. They do not transfer ownership of CR8OR business state.

## Integration Rule

**CR8OR owns business state and rules. External services execute specialized work and return results.**

Every integration should account for authentication, authorization, secret handling, validation, retries, timeouts, idempotency, external identifiers, failure states and auditability.

## n8n
**Role:** Cross-service workflow orchestration. n8n may coordinate multi-step workflows but must not become the authoritative store for CR8OR business state.

## Cloudflare R2
**Role:** Canonical file and generated-media storage. R2 stores files referenced by CR8OR; CR8OR owns business metadata, lifecycle and authorization.

## CR8OR Media
**Role:** Specialized media generation/rendering execution. CR8OR owns the originating request, lifecycle state and relationship to business/content state.

## Canva
**Role:** Human-assisted creative workflow and design execution. CR8OR remains authoritative for the business/content/media request causing the workflow.

## Postiz
**Role:** Social publishing execution. CR8OR owns publication intent, authorization and recorded publication state.

## GitHub
**Role:** Software-development execution and repository workflow. GitHub is not the authoritative source for CR8OR business domain state.

## AI Model Providers
**Role:** Reasoning and generation. Model providers generate outputs but do not own CR8OR business knowledge, authorization or historical state.

## Failure, Retry and Webhook Rules

External calls may fail after CR8OR accepts an operation. Such states must be explicit. Retryable operations should use idempotency where duplicate execution could create duplicate effects. External identifiers should be persisted when needed for callbacks or reconciliation. Incoming webhooks must be authenticated where supported, validated, safely deduplicated and associated with relevant integration state.

Provider-specific schemas, credentials implementation, webhook endpoints and concrete retry policies are deferred.
