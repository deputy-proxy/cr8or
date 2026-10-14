# Publishing adapter contract

## Ownership

CR8OR remains authoritative for publication readiness, approval, scheduling, submission attempts, tenant boundaries, idempotency, reconciliation, and immutable publication history. Network adapters are bounded external API clients and must not own CR8OR publication state.

## Resolution

PublishingService resolves an adapter through PublishingAdapterRegistry using the immutable publication destination's canonical network key. Adapter identity (adapter_key), network, destination external ID, and credential connection/reference are distinct. A destination external ID is never treated as an integration-connection ID.

Each publishing.adapters.<network> entry defines:

- key: stable adapter identity recorded in history;
- class: implementation of PublishingAdapter;
- enabled: explicit enablement; unknown, invalid, or disabled entries fail closed;
- native_scheduling: explicit opt-in to adapter-managed scheduling, honored only when the adapter declares scheduling support.

All six currently supported target networks have dedicated native adapter implementations and remain disabled by default until their deployment-specific API permissions, user consent/settings, access-plan requirements and real-account verification are complete. Test adapters are configured only in the testing environment.

## Contracts

PublishingAdapterRequest carries the canonical network, destination external ID, text/media descriptors, scheduled time when native scheduling is explicitly enabled, CR8OR idempotency and correlation keys, and an opaque credential reference/connection ID. It never carries raw access or refresh tokens.

PublishingCapabilities declares supported networks, media MIME types, text limits, scheduling support, and safe idempotency support. Resolution verifies that the adapter declares the requested network. Content is rejected before submission if the adapter is disabled, the connection is not operational, content exceeds declared limits, media is unsupported, or no safe idempotency strategy exists.

PublishingAdapterResult normalizes external ID, URL, status, and provider payload. PublishingAdapterException carries a stable failure code, retryability, and ambiguity. Authentication-required or invalid-grant outcomes mark the connection reconnect_required. Ambiguous outcomes become unknown; CR8OR blocks resubmission until reconciliation resolves them. Provider payloads are sanitized before persistence so credential-shaped fields are excluded.

## Lifecycle and reconciliation

Scheduling and approvals remain in CR8OR. Native scheduling is used only when explicitly enabled and supported. Otherwise CR8OR submits when the scheduled time is due.

Each publishing job stores the resolved adapter/network/destination. A retry must use the same adapter and destination. Definitive failures may be retried with the same idempotency key; ambiguous outcomes cannot be retried blindly.

Reconciliation appends immutable history. Accepted/submitted/pending results move unresolved publications into submitted; confirmed success moves them into succeeded; confirmed rejection moves them into failed. Terminal states are protected against delayed results. External results must be correlated to the exact publication/job before reconciliation.

Historical result rows retain their original provider attribution. The migration adds network and destination context from the immutable publication target without rewriting the historical provider field.

## Security and tenancy

- Re-check content readiness, active account/channel, organization ownership, enterprise ownership, identity-to-network compatibility, and active connection state before external submission.
- Require an authorized SocialIdentity and operational IntegrationConnection for native destinations.
- Resolve destination and credential context from persisted domain records, not Agent-facing input.
- Never log or persist raw credentials, authorization codes, or provider request headers.
- Keep network-specific business logic out of MCP tools and Filament resources.

## Adapter validation

Before enabling a network, add tests for success, asynchronous acceptance, provider rejection, rate limits, authentication failures, malformed responses, timeout before/after remote acceptance, duplicate idempotency keys, unsupported media, text limits, and credential rotation/revocation. Verify provider app approval, scopes, quotas, native scheduling semantics, and destination eligibility independently of CI.

## Manual publishing from Filament

Authorized enterprise owners and administrators can operate the content lifecycle without starting an Agent or Workflow execution:

1. In **Content Items**, submit a draft for review and use **Approve** on an item in review.
2. On an approved item, use **Mark publication ready**. This is an explicit human action authorized by the ContentItem policy; it is separate from the execution-bound approval required for Agent/Workflow capability calls.
3. On a publication-ready item, use **Publish now**, select an active connected social account for the same enterprise and content channel, and confirm.
4. Inspect **Publications** for provider status, external ID/URL, timestamps, and any recorded failure. Publication and provider-result records remain read-only historical records.

The UI delegates lifecycle changes to application services. The publishing service rechecks authorization, content state, account/channel/enterprise scope, the active integration connection, and provider adapter constraints. Repeated submissions reuse the existing publication record and idempotency identity; ambiguous provider outcomes are not retried blindly. Agent and Workflow execution paths continue to require their own matching, unexpired approval requests.